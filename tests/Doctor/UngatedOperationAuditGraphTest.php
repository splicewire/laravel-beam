<?php

namespace Splicewire\Beam\Tests\Doctor;

use PHPUnit\Framework\Attributes\Test;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\UngatedOperationAudit;
use Splicewire\Beam\Graph\Edges\ParentEdge;
use Splicewire\Beam\Graph\GraphOperations;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphTreeNode;
use Splicewire\Beam\Tests\TestCase;

/**
 * **A ParentEdge resource's four derived graph reads are ACKNOWLEDGED by the operation-gate audit, not
 * counted as residue** (laravel-beam fix for the flagship top-up's 2 reds, coord-lead@migration-flatten R2).
 *
 * The four carry `ability: null` on purpose: their gate is the resource read gate plus the scoped store
 * ({@see GraphOperations}' docblock). Before this, the first host resource with a `graph:` slot (the
 * flagship's `silos`, taxonomy a1867c8c) turned `particle.operation-gate` from pass to warn. The audit
 * now reports them under their own heading. A host's own null-ability Read still warns, at any key,
 * including a host op at a derived name, which deriveFor() never displaces.
 */
class UngatedOperationAuditGraphTest extends TestCase
{
    private function resource(string $key = 'tree-nodes'): ParticleResource
    {
        return new ParticleResource(
            key: $key,
            backing: GraphTreeNode::class,
            readOnly: true,
            graph: new ParentEdge('parent_id', label: 'name'),
        );
    }

    private function hostRead(string $resource, string $name): ParticleOperation
    {
        return new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: OperationKind::Read,
            model: BeamParticle::class,
            handle: fn () => null,
        );
    }

    /** @return object the audit's one finding */
    private function finding(ParticleOperationRegistry $registry): object
    {
        $findings = (new UngatedOperationAudit($registry))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(UngatedOperationAudit::CHECK, $findings[0]->check);

        return $findings[0];
    }

    #[Test]
    public function test_a_parent_edge_resources_four_graph_reads_read_as_acknowledged(): void
    {
        $registry = new ParticleOperationRegistry;
        GraphOperations::deriveFor($this->resource(), $registry);

        // Control: the four really are null-ability Reads, so a pass below is the acknowledgement, not a
        // population that was never undeclared.
        $this->assertCount(4, $registry->all());
        foreach ($registry->all() as $op) {
            $this->assertTrue($op->gateUndeclared(), $op->key());
            $this->assertSame(OperationKind::Read, $op->kind, $op->key());
        }

        $finding = $this->finding($registry);

        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        // Counted inside the population, not beside it (review N2): "0/4 …, and the 4 acknowledged …".
        $this->assertStringContainsString('0/4 non-write particle operations declare their authorization, and the 4 acknowledged derived graph reads (', $finding->detail);
        $this->assertStringNotContainsString('more', $finding->detail);
        foreach (GraphOperations::NAMES as $name) {
            $this->assertStringContainsString("tree-nodes.{$name}", $finding->detail);
        }
        // They are still `null`, so the pass must not call the flip clear (review N1).
        $this->assertStringNotContainsString('Both halves empty', $finding->detail);
        $this->assertStringContainsString('The null → derived flip is still not clear: it must exempt the 4 acknowledged derived graph reads', $finding->detail);
    }

    #[Test]
    public function test_a_hand_declared_null_read_on_the_same_host_still_warns(): void
    {
        $registry = new ParticleOperationRegistry;
        GraphOperations::deriveFor($this->resource(), $registry);
        $registry->register($this->hostRead('tree-nodes', 'export'));

        $finding = $this->finding($registry);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('1 of 5', $finding->detail);
        $this->assertStringContainsString("ability: 'tree-nodes.export'", $finding->detail);
        // The four are reported, under their own heading, and never as lines to fix.
        $this->assertStringContainsString('Not counted as residue: the 4 acknowledged derived graph reads (', $finding->detail);
        $this->assertStringNotContainsString("ability: 'tree-nodes.graph", $finding->detail);
        // The note follows the list; it never splits the list header from its lines (review N3).
        $this->assertStringContainsString("shown beside it:\n  tree-nodes.export", $finding->detail);
        $this->assertGreaterThan(
            strpos($finding->detail, "ability: 'tree-nodes.export'"),
            strpos($finding->detail, 'Not counted as residue'),
        );
        $this->assertStringContainsString('The null → derived flip is still not clear', $finding->detail);
    }

    #[Test]
    public function test_a_host_op_at_a_derived_name_is_not_acknowledged(): void
    {
        $registry = new ParticleOperationRegistry;
        // The host registers its own `graph.path` first; deriveFor() leaves it to the host (review N5).
        $registry->register($this->hostRead('tree-nodes', 'graph.path'), by: 'acme/host');
        GraphOperations::deriveFor($this->resource(), $registry);

        $this->assertFalse(GraphOperations::isDerived($registry->get('tree-nodes', 'graph.path'), $registry));
        $this->assertTrue(GraphOperations::isDerived($registry->get('tree-nodes', 'graph'), $registry));

        $finding = $this->finding($registry);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('1 of 4', $finding->detail);
        $this->assertStringContainsString("ability: 'tree-nodes.graph.path'", $finding->detail);
        $this->assertStringContainsString('Not counted as residue: the 3 acknowledged derived graph reads (', $finding->detail);
    }

    #[Test]
    public function test_registering_the_resource_records_the_four_under_beam(): void
    {
        // The real path: the resource registry derives into the container's operation registry.
        $this->app->make(ParticleResourceRegistry::class)->register($this->resource('audit-tree'));
        $operations = $this->app->make(ParticleOperationRegistry::class);

        foreach (GraphOperations::NAMES as $name) {
            $op = $operations->get('audit-tree', $name);
            $this->assertNotNull($op, $name);
            $this->assertTrue(GraphOperations::isDerived($op, $operations), $name);
        }
    }
}
