<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * **The reportable absence behind the Scribe strategies' ASK** (api-surface-coherence 102).
 *
 * A route carries `_particle` (from `Route::particleResource()` or a hand-rolled `->beam()->inResource()`)
 * or the `_particle_op_*` pair. Those stamps are how every reader — the group resolver, the response
 * strategy, the list-parameter strategy — finds the declaration behind the route. When the stamp names
 * a key that is not registered **on this host**, those readers have nothing to read, and the endpoint
 * documents thinner than it should: no response envelope, no filter/sort/include contract, no `input:`.
 *
 * ## Why this exists as an audit and not as the throw it replaced
 *
 * `ParticleListParameterStrategy` used to call `ParticleResourceRegistry::get()` and demand. Its sibling
 * `ParticleResponseStrategy`'s own docblock had already stated the governing rule — *a route mounted for
 * an unregistered thing is a reportable absence, not a reason to fail an entire spec build* — and the
 * list arm never got the memo. Measured at `~/Herd/splicewire-app` on 2026-08-27: **30 live routes**
 * (20 under `guest-links`, 10 under `releases`) threw per-route inside Scribe, which catches, prints
 * only under `-v`, and finishes on `WARN Generated docs, but encountered some errors`. All 30 were
 * therefore missing from `openapi.yaml`, from the published SDK and from the docs surface, with nothing
 * on screen to say so. Every one of the 30 endpoints answered **200** when hit.
 *
 * It is the estate's *"a check whose answer depends on the host must not throw"* rule, one more time:
 * whether `guest-links` is a registered particle resource **here** is a fact about the host.
 *
 * ## Why Warn and not Fail
 *
 * `inResource($key, filters: true)` is a *legitimate* declaration whose argument is a **data-filters**
 * resource key; nothing requires that key to also carry a `#[ParticleResource]`, and at the flagship
 * neither `guest-links` nor `releases` does. Such a route still documents its full filter/sort/include
 * contract off the data-filters registry — it just loses the particle-derived response envelope and
 * pagination. That is worth a line in a work-list, not a red gate. A stamp registered in **neither**
 * registry is the sharper case and is called out separately in the same finding.
 *
 * ## What it cannot see
 *
 * Only the routes registered in the running host, which is the point — this answers "what is thin
 * *here*", and every host serves a different set.
 */
class ParticleRouteResourceAudit implements DoctorAudit
{
    public const CHECK = 'particle.route-resource';

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
        private ParticleOperationRegistry $operations,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $stamped = 0;
        $unregistered = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $defaults = $route->defaults;

            $opResource = $defaults[ParticleOperationController::RESOURCE] ?? null;
            $opName = $defaults[ParticleOperationController::NAME] ?? null;

            if (is_string($opResource) && is_string($opName)) {
                $stamped++;

                if ($this->operations->find($opResource, $opName) === null) {
                    $unregistered["op:{$opResource}:{$opName}"][] = $this->label($route);
                }

                continue;
            }

            $key = $defaults[ParticleController::RESOURCE] ?? null;

            if (! is_string($key)) {
                continue;
            }

            $stamped++;

            if ($this->resources->find($key) === null) {
                $unregistered[$key][] = $this->label($route);
            }
        }

        if ($stamped === 0) {
            return [Finding::inconclusive(self::CHECK, 'No route on this host carries a particle stamp — nothing to resolve.')];
        }

        if ($unregistered === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d particle-stamped route%s; every stamp resolves to a declaration registered on this host.',
                $stamped,
                $stamped === 1 ? '' : 's',
            ))];
        }

        $rows = [];
        $affected = 0;

        foreach ($unregistered as $key => $routes) {
            $affected += count($routes);
            $rows[] = sprintf('[%s] %d route%s%s (e.g. %s)', $key, count($routes), count($routes) === 1 ? '' : 's',
                $this->filterNote($key), $routes[0]);
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d particle-stamped route%s name a key with no declaration registered on this host, so they '
                .'document without the particle-derived response envelope (and, where the key is unknown to '
                .'data-filters too, without any query contract): %s. This is advisory — `inResource()` may name a '
                .'data-filters resource on purpose — but a key registered NOWHERE is a mount pointing at nothing.',
            $affected,
            $stamped,
            $stamped === 1 ? '' : 's',
            implode('; ', $rows),
        ))];
    }

    /**
     * Whether an unregistered particle key is at least a data-filters resource — the difference between a
     * deliberate hand-rolled exposure and a stamp pointing at nothing.
     */
    private function filterNote(string $key): string
    {
        if (str_starts_with($key, 'op:')) {
            return ' — no such particle operation';
        }

        return DataFilter::registry()->has($key)
            ? ' — data-filters only, no `#[ParticleResource]`'
            : ' — registered in NEITHER registry';
    }

    private function label(Route $route): string
    {
        return sprintf('%s %s', $route->methods()[0] ?? 'GET', $route->uri());
    }
}
