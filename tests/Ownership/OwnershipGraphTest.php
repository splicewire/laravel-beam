<?php

namespace Splicewire\Beam\Tests\Ownership;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ownership\OwnershipCycleDetected;
use Splicewire\Beam\Ownership\OwnershipEdgeType;
use Splicewire\Beam\Ownership\OwnershipGraph;
use Splicewire\Beam\Ownership\Testing\ArrayOwnershipEdgeStore;

/**
 * The cascade / refcount / cycle-guard / diff LOGIC of the ownership-GC graph (sourced-particles
 * ticket 08, ADR-0161 Position 3). Framework-free (extends PHPUnit's base case, no testbench boot —
 * beam's harness is mid-refactor-broken) over the in-memory {@see ArrayOwnershipEdgeStore}, which
 * replicates the shipping store's walks in PHP. The live recursive-CTE SQL is verified separately in
 * {@see LiveCteCascadeSqliteTest}.
 *
 * This graph is NOT audit-lineage — nothing here touches Lineage; it is the live, cascade-on-evict twin.
 */
class OwnershipGraphTest extends TestCase
{
    private function graph(): array
    {
        $store = new ArrayOwnershipEdgeStore;

        return [new OwnershipGraph($store), $store];
    }

    public function test_owns_cascade_deletes_the_owned_subtree(): void
    {
        [$graph, $store] = $this->graph();

        // root -owns-> a -owns-> b  (a chain the root exclusively owns)
        $graph->record('root', 'a', OwnershipEdgeType::Owns);
        $graph->record('a', 'b', OwnershipEdgeType::Owns);

        $deleted = $graph->evict('root');

        $this->assertEqualsCanonicalizing(['root', 'a', 'b'], $deleted);
        $this->assertFalse($store->has('a'));
        $this->assertFalse($store->has('b'));
    }

    public function test_a_references_target_survives_an_evict(): void
    {
        [$graph, $store] = $this->graph();

        // root -references-> pre  (a pre-existing locally-owned entity — never cascades)
        $graph->record('root', 'pre', OwnershipEdgeType::References);

        $deleted = $graph->evict('root');

        $this->assertSame(['root'], $deleted);
        $this->assertTrue($store->has('pre'), 'a referenced target must survive its referrer eviction');
    }

    public function test_refcount_owns_target_with_a_second_non_evicted_owner_survives(): void
    {
        [$graph, $store] = $this->graph();

        // root -owns-> shared  AND  keeper -owns-> shared  (shared has two owners)
        $graph->record('root', 'shared', OwnershipEdgeType::Owns);
        $graph->record('keeper', 'shared', OwnershipEdgeType::Owns);

        $deleted = $graph->evict('root');

        $this->assertSame(['root'], $deleted, 'shared must survive — keeper still owns it');
        $this->assertTrue($store->has('shared'));
    }

    public function test_refcount_owns_target_dies_when_its_only_surviving_owner_is_also_evicted(): void
    {
        [$graph, $store] = $this->graph();

        // root -owns-> a ; root -owns-> b ; a -owns-> shared ; b -owns-> shared
        // shared has two owners but BOTH are inside the evicted subtree → shared dies.
        $graph->record('root', 'a', OwnershipEdgeType::Owns);
        $graph->record('root', 'b', OwnershipEdgeType::Owns);
        $graph->record('a', 'shared', OwnershipEdgeType::Owns);
        $graph->record('b', 'shared', OwnershipEdgeType::Owns);

        $deleted = $graph->evict('root');

        $this->assertContains('shared', $deleted);
        $this->assertFalse($store->has('shared'));
    }

    public function test_cycle_guard_refuses_a_cycle_on_materialize(): void
    {
        [$graph] = $this->graph();

        $graph->record('a', 'b', OwnershipEdgeType::Owns);

        $this->expectException(OwnershipCycleDetected::class);
        $graph->record('b', 'a', OwnershipEdgeType::Owns); // b already reached from a → cycle
    }

    public function test_self_edge_is_refused(): void
    {
        [$graph] = $this->graph();

        $this->expectException(OwnershipCycleDetected::class);
        $graph->record('x', 'x', OwnershipEdgeType::Owns);
    }

    public function test_depth_guard_fires_on_the_materialize_recursion(): void
    {
        [$graph] = $this->graph();

        // limit is 3 owned hops. a->b->c->d is 3 hops (ok); adding d->e is the 4th → refused.
        $graph->record('a', 'b', OwnershipEdgeType::Owns);
        $graph->record('b', 'c', OwnershipEdgeType::Owns);
        $graph->record('c', 'd', OwnershipEdgeType::Owns);

        $this->expectException(OwnershipCycleDetected::class);
        $graph->record('d', 'e', OwnershipEdgeType::Owns);
    }

    public function test_references_edges_skip_the_cycle_and_depth_guard(): void
    {
        [$graph, $store] = $this->graph();

        // A deep references chain is fine (references never cascade, so cannot cycle the GC walk).
        $graph->record('a', 'b', OwnershipEdgeType::References);
        $graph->record('b', 'c', OwnershipEdgeType::References);
        $graph->record('c', 'd', OwnershipEdgeType::References);
        $graph->record('d', 'e', OwnershipEdgeType::References);

        $this->assertCount(4, $store->all());
    }

    public function test_refresh_diffs_added_removed_kept_without_thrashing_unchanged(): void
    {
        [$graph] = $this->graph();

        // old revision: node owns x and y, references r.
        $graph->record('node', 'x', OwnershipEdgeType::Owns);
        $graph->record('node', 'y', OwnershipEdgeType::Owns);
        $graph->record('node', 'r', OwnershipEdgeType::References);

        // new revision: keeps x (unchanged), drops y (owned → cascade-evicted), adds z, keeps r.
        $diff = $graph->refresh('node', 'R1', 'R2', [
            'x' => OwnershipEdgeType::Owns,
            'z' => OwnershipEdgeType::Owns,
            'r' => OwnershipEdgeType::References,
        ]);

        $this->assertEqualsCanonicalizing(['x', 'r'], $diff['kept'], 'unchanged edges are left alone (no thrash)');
        $this->assertSame(['z'], $diff['added']);
        $this->assertSame(['y'], $diff['removed']);
    }

    public function test_refresh_dropped_owned_target_survives_if_another_owner_holds_it(): void
    {
        [$graph, $store] = $this->graph();

        $graph->record('node', 'y', OwnershipEdgeType::Owns);
        $graph->record('other', 'y', OwnershipEdgeType::Owns); // y is shared

        // node's new revision drops y; but `other` still owns it → y survives the refresh evict.
        $graph->refresh('node', 'R1', 'R2', []);

        $this->assertTrue($store->has('y'), 'refresh-dropped owned target survives while another owner holds it');
    }

    public function test_promote_reparents_minted_references_to_owns(): void
    {
        [$graph, $store] = $this->graph();

        // A cached shadow references two things: `minted` (a foreign-only assoc graduated this promotion)
        // and `local` (a pre-existing tenant entity — must stay a reference).
        $graph->record('shadow', 'minted', OwnershipEdgeType::References);
        $graph->record('shadow', 'local', OwnershipEdgeType::References);

        $reparented = $graph->promote('shadow', ['minted']);

        $this->assertSame(['minted'], $reparented);

        $out = $store->outEdges('shadow');
        $this->assertSame(OwnershipEdgeType::Owns, $out['minted'], 'minted assoc is now owned (cascades on evict)');
        $this->assertSame(OwnershipEdgeType::References, $out['local'], 'pre-existing local reference is never claimed');
    }
}
