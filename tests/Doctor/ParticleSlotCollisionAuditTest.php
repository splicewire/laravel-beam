<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Tests\TestCase;

/**
 * The gate particle-operation-surface 05 must pass before `/op/` may be dropped.
 *
 * Two assertions carry the ticket. {@see test_a_rendering_and_an_operation_sharing_a_name_collide}
 * is the collision the ticket was written about — two popcorn roots that structurally cannot see each
 * other. {@see test_a_hand_written_route_in_the_slot_collides_too} is the one it did not name, and the
 * reason this is a route-table check rather than a registry merge: at `~/Herd/splicewire-app`,
 * `POST api/v1/circuits/{id}/intake` shares the `circuits` slot with a rendering and an operation while
 * being unknown to every registry in the estate.
 *
 * {@see test_two_non_operation_routes_sharing_a_slot_are_not_this_audits_business} is what keeps the
 * estate's normal reading empty — measured at zero across twenty booted hosts on 2026-08-27.
 */
class ParticleSlotCollisionAuditTest extends TestCase
{
    private function audit(): ParticleSlotCollisionAudit
    {
        return new ParticleSlotCollisionAudit(app(Router::class));
    }

    private function operation(string $uri, string $resource, string $name, string $verb = 'post'): void
    {
        app(Router::class)->{$verb}($uri, fn () => null)
            ->defaults(ParticleOperationController::RESOURCE, $resource)
            ->defaults(ParticleOperationController::NAME, $name)
            ->name("{$resource}.op.{$name}");
    }

    private function rendering(string $uri, string $name, string $verb = 'get'): void
    {
        app(Router::class)->{$verb}($uri, fn () => null)
            ->defaults(RenderingsController::CONFIG, ['rendering' => $name])
            ->name($name);
    }

    public function test_a_host_with_no_mounted_operation_passes(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ParticleSlotCollisionAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('nothing to collide over', $findings[0]->detail);
    }

    public function test_operations_that_move_into_a_free_slot_pass(): void
    {
        $this->operation('widgets/{id}/op/publish', 'widgets', 'publish');
        $this->rendering('widgets/{id}/export', 'widgets.export');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 mounted particle operation', $findings[0]->detail);
    }

    public function test_a_rendering_and_an_operation_sharing_a_name_collide(): void
    {
        // `POST widgets/{id}/op/export` collapses to `POST widgets/{id}/export`, which the rendering's
        // certified write twin already claims. Neither registry can see this: they are different roots.
        $this->operation('widgets/{id}/op/export', 'widgets', 'export');
        $this->rendering('widgets/{id}/export', 'widgets.export', verb: 'post');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('URI [|POST /widgets/{id}/export]', $findings[0]->detail);
        $this->assertStringContainsString('(operation)', $findings[0]->detail);
        $this->assertStringContainsString('(rendering)', $findings[0]->detail);
    }

    public function test_a_hand_written_route_in_the_slot_collides_too(): void
    {
        $this->operation('circuits/{id}/op/intake', 'circuits', 'intake');
        app(Router::class)->post('circuits/{id}/intake', fn () => null)->name('circuits.intake');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('(hand-written)', $findings[0]->detail);
    }

    public function test_a_route_name_collision_is_reported_even_when_the_uris_differ(): void
    {
        // The quieter axis: `route()` generates the last registration's URL while a request matches the
        // first, and Laravel only refuses the pair at `route:cache`.
        $this->operation('widgets/{id}/op/run', 'widgets', 'run');
        app(Router::class)->get('widgets/{id}/runs', fn () => null)->name('widgets.run');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('route name [widgets.run]', $findings[0]->detail);
    }

    public function test_two_non_operation_routes_sharing_a_slot_are_not_this_audits_business(): void
    {
        // `prognosix-api`'s OPTIONS catch-all and `prognosix-web-app`'s `settings` redirect are both
        // legitimate pre-existing pairs. Dropping `/op/` neither creates nor disturbs them.
        $this->operation('widgets/{id}/op/publish', 'widgets', 'publish');
        app(Router::class)->get('settings', fn () => null)->name('settings');
        app(Router::class)->get('settings', fn () => null);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_a_crud_verb_claimant_is_named_as_such(): void
    {
        $this->operation('widgets/{id}/op/latest', 'widgets', 'latest');
        app(Router::class)->post('widgets/{id}/latest', fn () => null)
            ->defaults(ParticleController::RESOURCE, 'widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('(particle CRUD)', $findings[0]->detail);
    }
}
