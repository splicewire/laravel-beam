<?php

namespace Splicewire\Beam\Tests\Ownership;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ownership\EloquentOwnershipEdgeStore;
use Splicewire\Beam\Ownership\OwnershipEdgeType;
use Splicewire\Beam\Ownership\OwnershipGraph;

/**
 * Verifies the LIVE recursive-CTE cascade against a real (sqlite in-memory) database — the SQL the
 * shipping {@see EloquentOwnershipEdgeStore} runs, not the PHP twin (sourced-particles ticket 08).
 *
 * Framework-free: it spins up an Illuminate\Database Capsule directly (no testbench boot — beam's harness
 * is mid-refactor-broken). sqlite honours `WITH RECURSIVE`, so the CTE shape is exercised end-to-end; the
 * HOST verifies the SAME SQL against Postgres after adding the runnable migration (both engines honour the
 * syntax — this test proves the query composes and terminates; the host proves it on the production engine).
 *
 * The Capsule is booted as the default connection so DB::connection() (used by the store) resolves to it.
 */
class LiveCteCascadeSqliteTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip cleanly if the framework is not installed (e.g. a linting-only checkout).
        if (! class_exists(Capsule::class)) {
            $this->markTestSkipped('Illuminate database capsule not available.');
        }

        // A minimal container so Beam::table()'s config('beam.core.table_prefix', 'beam_') resolves
        // (the store routes table names through that seam). We set the default prefix explicitly.
        $container = new Container;
        $container->instance('config', new ConfigRepository(['beam' => ['core' => ['table_prefix' => 'beam_']]]));
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();
        $schema->create('beam_ownership_edges', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->uuid('target_id');
            $table->string('edge_type')->default('owns');
            $table->timestamps();
            $table->unique(['owner_id', 'target_id', 'edge_type']);
        });
        $schema->create('beam_particles', function ($table) {
            $table->uuid('id')->primary();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Capsule::connection()->disconnect();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function seedParticle(string $id): void
    {
        Capsule::table('beam_particles')->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function graph(): OwnershipGraph
    {
        return new OwnershipGraph(new EloquentOwnershipEdgeStore(Capsule::connection()));
    }

    public function test_live_cte_walks_a_deep_owns_chain(): void
    {
        $store = new EloquentOwnershipEdgeStore(Capsule::connection());
        $graph = new OwnershipGraph($store);

        // root -owns-> a -owns-> b (chain within the depth guard).
        $graph->record('root', 'a', OwnershipEdgeType::Owns);
        $graph->record('a', 'b', OwnershipEdgeType::Owns);

        $subtree = $store->ownedSubtree(['root']);

        $this->assertEqualsCanonicalizing(['a', 'b'], $subtree, 'the recursive CTE reaches the whole owned chain');
    }

    public function test_live_cte_cascade_deletes_owned_particles_but_spares_references(): void
    {
        foreach (['root', 'a', 'b', 'pre'] as $id) {
            $this->seedParticle($id);
        }

        $graph = $this->graph();
        $graph->record('root', 'a', OwnershipEdgeType::Owns);
        $graph->record('a', 'b', OwnershipEdgeType::Owns);
        $graph->record('root', 'pre', OwnershipEdgeType::References);

        $deleted = $graph->evict('root');

        $this->assertEqualsCanonicalizing(['root', 'a', 'b'], $deleted);
        // Owned particles GC'd from the store:
        $this->assertNull(Capsule::table('beam_particles')->find('a'));
        $this->assertNull(Capsule::table('beam_particles')->find('b'));
        // Referenced particle survives:
        $this->assertNotNull(Capsule::table('beam_particles')->find('pre'));
    }

    public function test_live_cte_refcount_spares_a_shared_owned_target(): void
    {
        foreach (['root', 'keeper', 'shared'] as $id) {
            $this->seedParticle($id);
        }

        $graph = $this->graph();
        $graph->record('root', 'shared', OwnershipEdgeType::Owns);
        $graph->record('keeper', 'shared', OwnershipEdgeType::Owns);

        $graph->evict('root');

        $this->assertNotNull(Capsule::table('beam_particles')->find('shared'), 'keeper still owns shared');
    }

    public function test_references_are_not_traversed_by_the_owns_cte(): void
    {
        $store = new EloquentOwnershipEdgeStore(Capsule::connection());
        $graph = new OwnershipGraph($store);

        $graph->record('root', 'ref', OwnershipEdgeType::References);

        $this->assertSame([], $store->ownedSubtree(['root']), 'references edges are excluded from the owns walk');
    }
}
