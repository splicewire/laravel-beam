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

/**
 * particle-operation-surface 15 — the audit that catches a relative edge which will not scope.
 *
 * The `filterable` case is the one worth having, and it is worth having because `filterable` **defaults
 * to true**: an author registering a resource has to know to opt OUT, and the reason to opt out lives in
 * a branch of `ParticleController::index()` that nothing points at from the declaration site.
 *
 * ⚠️ These tests exist in the form they do because the estate's recurring defect is *an instrument that
 * reports success by not running*. A test that only asserts the clean case would pass against an audit
 * that returns `pass()` unconditionally. Every case below asserts the audit **fires**, and names the
 * substring that makes the finding actionable.
 */
class RelativeEdgeIntegrityAuditTest extends TestCase
{
    private function audit(): RelativeEdgeIntegrityAudit
    {
        return $this->app->make(RelativeEdgeIntegrityAudit::class);
    }

    private function registerResource(string $key, bool $filterable): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: EdgeChild::class,
            filterable: $filterable,
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

    public function test_it_passes_for_an_edge_whose_child_opts_out_of_filterable(): void
    {
        // The shape `beam-media` ships, and the only shape under which the nested index actually scopes.
        $this->registerResource('edge-children', filterable: false);
        $this->registerEdge('edge-children');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('scopes its child through the bound parent', $findings[0]->detail);
    }

    public function test_it_reports_a_filterable_child_whose_parent_query_is_discarded(): void
    {
        // THE CASE THIS AUDIT EXISTS FOR. `ParticleController::index()` rides the data-filters builder for
        // a filterable resource and drops `$relativeQuery`, so the nested URL lists the whole table — a
        // 200 with too many rows, which no static check and no route:list can see.
        $this->registerResource('edge-children', filterable: true);
        $this->registerEdge('edge-children');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('DISCARDS the bound-parent query', $findings[0]->detail);
        $this->assertStringContainsString('edge-children', $findings[0]->detail);
        // The finding must name the closing move, not just the defect.
        $this->assertStringContainsString('filterable: false', $findings[0]->detail);
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
        $this->registerResource('edge-children', filterable: false);
        $this->registerEdge('edge-children', model: NotAModel::class);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('not an Eloquent model', $findings[0]->detail);
    }

    public function test_it_reports_every_problem_edge_not_just_the_first(): void
    {
        // A host with two broken edges wants both named. Reporting the first and a count is the shape
        // that sends someone back for a second run.
        $this->registerResource('edge-children', filterable: true);
        $this->registerEdge('edge-children');
        $this->registerEdge('also-never-registered');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('edge-children', $findings[0]->detail);
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
