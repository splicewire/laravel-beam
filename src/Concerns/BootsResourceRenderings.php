<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
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
            app(ParticleMounter::class)->resourceRenderings(
                router: $this,
                resource: $resource,
                subject: $subject,
                at: $at,
                abilities: $abilities,
                middleware: $middleware,
                with: $with,
                idConstraint: $idConstraint,
            );
        });
    }
}
