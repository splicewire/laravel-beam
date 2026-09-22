<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\RelativeEdgeIntegrityAudit;
use Splicewire\Beam\Particle\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class RelativeEdgeIntegrityAuditTest extends TestCase
{
    private function audit(): RelativeEdgeIntegrityAudit
    {
        return $this->app->make(RelativeEdgeIntegrityAudit::class);
    }

    private function registerResource(string $key): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: EdgeChild::class,
        ));
    }

    private function registerEdge(string $child, string $model = EdgeParent::class): void
    {
        $this->app->make(ParticleRelativeRegistry::class)->register(new ParticleRelative(
            child: $child,
            of: 'edge-parents',
            model: $model,
            via: 'children',
        ));
    }

    public function test_it_passes_when_no_edge_is_declared(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('No relative edge is declared', $findings[0]->detail);
    }

    public function test_it_passes_for_a_registered_child_with_an_eloquent_parent(): void
    {
        // A valid declared edge has a concrete parent and registered child.
        $this->registerResource('edge-children');
        $this->registerEdge('edge-children');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('scopes its child through the bound parent', $findings[0]->detail);
    }

    public function test_it_reports_a_child_resource_not_registered_on_this_host(): void
    {
        // A host fact, so it reports rather than throws: the child may be registered by a package this
        // host does not install, which makes the edge inert rather than wrong.
        $this->registerEdge('never-registered');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('not registered on this host', $findings[0]->detail);
    }

    public function test_it_reports_a_parent_model_that_is_not_eloquent(): void
    {
        // 07 D5: the edge route-model-binds its parent, so it is Eloquent-only by construction. The
        // refusal of a `RelatesRecords` port is recorded here so it is discoverable rather than silent.
        $this->registerResource('edge-children');
        $this->registerEdge('edge-children', model: NotAModel::class);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('not an Eloquent model', $findings[0]->detail);
    }

    public function test_it_reports_every_problem_edge_not_just_the_first(): void
    {
        // A host with two broken edges wants both named. Reporting the first and a count is the shape
        // that sends someone back for a second run.
        $this->registerResource('edge-children');
        $this->registerEdge('missing-child');
        $this->registerEdge('also-never-registered');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('missing-child', $findings[0]->detail);
        $this->assertStringContainsString('also-never-registered', $findings[0]->detail);
        $this->assertStringContainsString('2 relative edge problems', $findings[0]->detail);
    }
}

class EdgeParent extends Model
{
    protected $table = 'edge_parents';

    public $timestamps = false;
}

class EdgeChild extends Model
{
    protected $table = 'edge_children';

    public $timestamps = false;
}

class NotAModel {}
