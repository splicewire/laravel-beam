<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Rendering\Data\ResourceRenderingCatalogData;
use Splicewire\Beam\Rendering\Http\RenderingCatalogController;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Rendering\RenderingCertifier;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;

/**
 * The `Route::resourceRenderings()` macro (moved from laravel-composition-engine into beam core) —
 * mounts one read, and where {@see RenderingCertifier} could prove reversibility one write, route per
 * registered rendering.
 *
 * A link in {@see BeamServiceProvider}'s `boot` chain rather than a line in its `packageBooted()`.
 */
trait BootsResourceRenderings
{
    /**
     * Register `Route::resourceRenderings($resource, $subject, ...)`, which mounts EVERY rendering the
     * {@see ResourceRenderingRegistry} holds for `$resource` — one read route each, plus a write route
     * only where {@see RenderingCertifier} could prove reversibility:
     *
     *   GET  {at}/{id}/{rendering}  → show   (always)
     *   POST {at}/{id}/{rendering}  → store  (certified LosslessEligible only)
     *   GET  {at}/renderings        → index  (always — the discovery route, even for zero renderings)
     *
     * Modelled on `Route::recordVersions()`, including its load-bearing discipline: per-route config
     * rides `->defaults()` as a plain serializable array with NO closures, so the table survives
     * `route:cache`. What is frozen at mount time is only the certified fidelity and the verb grant it
     * implies; the rendering itself is re-read from the registry per request, so its live format
     * enumeration is never baked.
     *
     * **`Fidelity` decides the verb, and the certifier decides the fidelity.** A rendering cannot declare
     * itself writable — the write route simply does not exist for a lossy one, which is stronger than a
     * write route that accepts input and silently discards state.
     *
     * `$at` is where the routes MOUNT; `$resource` is the registry KEY. `recordVersions()` couples the two
     * (its own docblock notes the host must wrap it in a prefix/name group to reach a URL tier, "a name
     * can't hold a slash"), and that coupling is exactly what stops an existing endpoint from migrating
     * onto a macro without moving. Splitting them lets `$at: ''` mount at the CURRENT group's root — how
     * an already-grouped endpoint keeps its URI and route name to the byte.
     *
     * `$abilities` defaults to `view`/`update` against the subject. Pass `[]` to state explicitly that
     * this resource is gated elsewhere (route middleware) and the controller must not authorize — silence
     * is not the default, an empty map is a decision.
     */
    #[Chained('boot', order: 30)]
    protected function bootResourceRenderingsMacro(): void
    {
        if (Route::hasMacro('resourceRenderings')) {
            return;
        }

        Route::macro('resourceRenderings', function (
            string $resource,
            string $subject,
            ?string $at = null,
            ?array $abilities = null,
            array $middleware = [],
            array $with = [],
            string $idConstraint = 'uuid',
        ): void {
            /** @var Router $this */
            $at = $at ?? $resource;
            $abilities = $abilities ?? ['view' => 'view', 'mutate' => 'update'];

            $registry = app(ResourceRenderingRegistry::class);
            $certifier = app(RenderingCertifier::class);

            $grants = [];

            foreach ($registry->for($resource) as $rendering) {
                $fidelity = $certifier->certify($rendering);
                $writable = $fidelity === Fidelity::LosslessEligible;

                $config = [
                    'resource' => $resource,
                    'rendering' => $rendering->name(),
                    'subject' => $subject,
                    'with' => array_values($with),
                    'abilities' => $abilities,
                    'fidelity' => $fidelity->value,
                    'writable' => $writable,
                ];

                $uri = ($at === '' ? '' : $at.'/').'{id}/'.$rendering->name();
                $name = ($at === '' ? '' : $at.'.').$rendering->name();

                $mount = function (RouteInstance $route) use ($config, $middleware, $idConstraint): RouteInstance {
                    $route->defaults(RenderingsController::CONFIG, $config);

                    if ($idConstraint === 'uuid') {
                        $route->whereUuid('id');
                    }

                    if ($middleware !== []) {
                        $route->middleware($middleware);
                    }

                    return $route;
                };

                $mount($this->get($uri, [RenderingsController::class, 'show']))->name($name);

                if ($writable) {
                    $mount($this->post($uri, [RenderingsController::class, 'store']))->name($name.'.ingest');
                }

                $grants[$rendering->name()] = [
                    'fidelity' => $fidelity->value,
                    'writable' => $writable,
                ];
            }

            // The discovery route (api-surface-coherence ticket 33). OUTSIDE the loop deliberately: a
            // resource that mounts the macro and has declared no rendering still answers, with an empty
            // set. Absence of renderings is not absence of resource.
            //
            // It carries the mount-time grant map — the same certified verdict the read/write routes
            // freeze — while leaving the format enumeration to be re-read per request.
            //
            // It does NOT inherit `$middleware`. That parameter gates the RENDERING (compositions pass
            // `consume.engine`, which meters the dogfood loopback); metering a metadata read as engine
            // consumption would be a new cost on an endpoint that touches no engine. The route group's
            // own middleware still applies, which is where `abilities: []` says the gate lives.
            $catalogUri = ($at === '' ? '' : $at.'/').'renderings';
            $catalogName = ($at === '' ? '' : $at.'.').'renderings';

            $this->get($catalogUri, [RenderingCatalogController::class, 'index'])
                ->defaults(RenderingCatalogController::CONFIG, [
                    'resource' => $resource,
                    'subject' => $subject,
                    'abilities' => $abilities,
                    'renderings' => $grants,
                ])
                ->name($catalogName)
                ->beam()->returns(ResourceRenderingCatalogData::class);
        });
    }
}
