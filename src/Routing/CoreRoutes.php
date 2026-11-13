<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Ia\Http\HostRealmsController;
use Splicewire\Beam\Particle\Ops\ResetHookOp;
use Splicewire\Beam\Webhooks\Http\HookDeliveriesController;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

/** Package routes; the host supplies authentication, realm and tenancy middleware. */
final class CoreRoutes
{
    public static function mount(): void
    {
        ApiRoot::mount(app(Router::class), 'beam.core.api_root', 'core', function (Router $router): void {
            $router->name('beam.core.')->group(function (Router $router): void {
                $router->get('realms', HostRealmsController::class)->name('realms');

                // Literal catalogs precede record parameters, including on hosts without UUID constraints.
                $router->get('hooks/events', [HookEventCatalogController::class, 'index'])
                    ->defaults(HookEventCatalogController::CONFIG, ['resource' => null])
                    ->name('hooks.events');
                $router->get('hooks/{hook}/deliveries', [HookDeliveriesController::class, 'index'])
                    ->whereUuid('hook')->name('hooks.deliveries');

                // Creation is the reveal-once Frame handler; REST retains the existing exposure.
                Particle::mount('hooks')->only(['index', 'show', 'update', 'destroy'])->idConstraint('uuid');
                Particle::ops('hooks', 'hooks', [ResetHookOp::class]);
            });
        });
    }
}
