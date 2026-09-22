<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Routing\RouteActionMetadataReader;
use Splicewire\Beam\Tests\TestCase;

/**
 * Actual operation slots retain URI, method, domain and route-name distinctions.
 *
 * Two assertions carry the ticket. {@see test_a_rendering_and_an_operation_sharing_a_name_collide}
 * is the collision the ticket was written about — two popcorn roots that structurally cannot see each
 * other. {@see test_a_hand_written_route_in_the_slot_collides_too} is the one it did not name, and the
 * reason this is a route-table check rather than a registry merge: at `~/Herd/splicewire-app`,
 * `POST api/v1/circuits/{id}/intake` shares the `circuits` slot with a rendering and an operation while
 * being unknown to every registry in the estate.
 *
 * ⚠️ particle-operation-surface 13 deleted the rendering subsystem, so the `rendering` CLAIMANT no
 * longer exists as a stamp. The rendering fixture below is now a HAND-WRITTEN route in the slot, which
 * is what a host's own `GET {resource}/{id}/export` is once no registry owns it — and that is the
 * audit's third class, the one this suite was written to prove it can see. The collision it detects is
 * unchanged; only the label it reports moved.
 *
 * {@see test_two_non_operation_routes_sharing_a_slot_are_not_this_audits_business} is what keeps the
 * estate's normal reading empty — measured at zero across twenty booted hosts on 2026-08-27.
 */
class ParticleSlotCollisionAuditTest extends TestCase
{
    public function test_literal_op_segments_and_names_are_distinct_slots(): void
    {
        $this->operation('resources/op/widgets/{id}/publish', 'resources.op.widgets', 'publish');
        app(Router::class)->post('resources/widgets/{id}/publish', fn () => null)->name('resources.widgets.publish');
        $this->operation('widgets/{id}/op/run', 'widgets.op', 'run');
        app(Router::class)->get('widgets/{id}/run', fn () => null)->name('widgets.run');

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    public function test_different_methods_and_domains_do_not_share_uri_slots(): void
    {
        $this->operation('widgets/{id}/publish', 'widgets', 'publish');
        app(Router::class)->get('widgets/{id}/publish', fn () => null)->name('widgets.preview');
        app(Router::class)->domain('other.test')->group(function (): void {
            app(Router::class)->post('widgets/{id}/publish', fn () => null)->name('other.widgets.publish');
        });

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    private function audit(): ParticleSlotCollisionAudit
    {
        return new ParticleSlotCollisionAudit(app(Router::class), new RouteActionMetadataReader);
    }

    private function operation(string $uri, string $resource, string $name, string $verb = 'post'): void
    {
        // Overlapping method sets keep both registrations in Laravel's route table for collision tests.
        app(Router::class)->match([$verb, 'PATCH'], $uri, fn () => null)
            ->defaults(ParticleOperationController::RESOURCE, $resource)
            ->defaults(ParticleOperationController::NAME, $name)
            ->name("{$resource}.{$name}");
    }

    /**
     * A route in the `{resource}/{id}/{segment}` slot that no registry owns — what a rendering became
     * when particle-operation-surface 13 dissolved the registry that used to stamp it.
     */
    private function rendering(string $uri, string $name, string $verb = 'get'): void
    {
        app(Router::class)->{$verb}($uri, fn () => null)->name($name);
    }

    public function test_a_host_with_no_mounted_operation_passes(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ParticleSlotCollisionAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('nothing to collide over', $findings[0]->detail);
    }

    public function test_operations_in_a_free_slot_pass(): void
    {
        $this->operation('widgets/{id}/publish', 'widgets', 'publish');
        $this->rendering('widgets/{id}/export', 'widgets.export');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 mounted particle operation', $findings[0]->detail);
    }

    public function test_a_rendering_and_an_operation_sharing_a_name_collide(): void
    {
        // The operation and hand-written route claim the same POST URI.
        $this->operation('widgets/{id}/export', 'widgets', 'export');
        $this->rendering('widgets/{id}/export', 'widgets.export', verb: 'post');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('URI [|POST /widgets/{id}/export]', $findings[0]->detail);
        $this->assertStringContainsString('(operation)', $findings[0]->detail);
        $this->assertStringContainsString('(hand-written)', $findings[0]->detail);
    }

    public function test_a_hand_written_route_in_the_slot_collides_too(): void
    {
        $this->operation('circuits/{id}/intake', 'circuits', 'intake');
        app(Router::class)->post('circuits/{id}/intake', fn () => null)->name('circuits.intake');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('(hand-written)', $findings[0]->detail);
    }

    public function test_a_route_name_collision_is_reported_even_when_the_uris_differ(): void
    {
        // The quieter axis: `route()` generates the last registration's URL while a request matches the
        // first, and Laravel only refuses the pair at `route:cache`.
        $this->operation('widgets/{id}/run', 'widgets', 'run');
        app(Router::class)->get('widgets/{id}/runs', fn () => null)->name('widgets.run');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('route name [widgets.run]', $findings[0]->detail);
    }

    public function test_two_non_operation_routes_sharing_a_slot_are_not_this_audits_business(): void
    {
        // Different method sets keep both registrations in Laravel's route table while sharing GET.
        $this->operation('widgets/{id}/publish', 'widgets', 'publish');
        $plainRoute = app(Router::class)->match(['GET', 'POST'], 'settings', fn () => null)->name('settings.multi');
        app(Router::class)->get('settings', fn () => null)->name('settings.single');

        $this->assertCount(2, array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            fn ($route) => $route->uri() === 'settings',
        ));

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);

        // The same real collision becomes this audit's concern when one claimant is an operation.
        $plainRoute->defaults(ParticleOperationController::RESOURCE, 'settings')
            ->defaults(ParticleOperationController::NAME, 'preview');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('URI [|GET /settings]', $findings[0]->detail);
    }

    public function test_a_crud_verb_claimant_is_named_as_such(): void
    {
        $this->operation('widgets/{id}/latest', 'widgets', 'latest');
        app(Router::class)->post('widgets/{id}/latest', fn () => null)
            ->defaults(ParticleController::RESOURCE, 'widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('(particle CRUD)', $findings[0]->detail);
    }
}
