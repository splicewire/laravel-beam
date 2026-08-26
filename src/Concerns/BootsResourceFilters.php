<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantsData;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;

/**
 * The `Route::resourceFilters()` macro — the per-resource filter sub-surface
 * (api-surface-coherence ticket 10, build 35).
 *
 * A link in {@see BeamServiceProvider}'s `boot` chain rather than a line in its `packageBooted()`.
 *
 * ⚠️ `order: 5` — BEFORE `BootsParticleRouteMacros` (order 10), which calls this macro from inside
 * `particleResource`. It is an explicit order rather than `use`-statement order for the reason that
 * trait's own warning gives: pint's `ordered_traits` fixer sorts `use` statements alphabetically, so a
 * mount sequence resting on them would be resequenced by a formatter on an unrelated commit with
 * nothing failing.
 *
 * **This is modelled on {@see BootsResourceRenderings} on purpose**, and keeps its two disciplines:
 * per-route config rides `->defaults()` as a plain serializable array so `route:cache` survives it,
 * and only the RESOURCE KEY is frozen at mount time — the schema, the variant set and the option
 * vocabulary are all re-read per request. Freeze the verb, never the enumeration.
 *
 * Where it differs, and why: `resourceRenderings` is called by hand per resource, because a rendering
 * is something a host opts a resource into. A filter vocabulary is not opt-in — a resource either has a
 * data-filters registration or it does not — so this mounts AUTOMATICALLY from `particleResource`, at
 * every exposure, including nested ones. That is ticket 10 §2's *registration is one, exposure is many*
 * taken literally: the sub-surface follows the resource, not the URL.
 */
trait BootsResourceFilters
{
    #[Chained('boot', order: 5)]
    protected function bootResourceFiltersMacro(): void
    {
        if (Route::hasMacro('resourceFilters')) {
            return;
        }

        Route::macro('resourceFilters', function (
            ?string $resource,
            string $at = '',
            ?string $names = null,
            array $middleware = [],
            string $idConstraint = 'uuid',
        ): void {
            /** @var Router $this */

            // A NULL resource is the Frame-resource-root case and only that: `{resource}` there is the
            // registration key by construction, so the controller reads it off the route parameter. For
            // every other mount the key is frozen here and the URI segment is never consulted — half
            // the estate's filter keys diverge from their URL word (ticket 10 §1) and that divergence
            // is legitimate.
            $config = ['resource' => $resource];

            $prefix = $at === '' ? 'filters' : rtrim($at, '/').'/filters';

            // An EMPTY `$names` is meaningful, not missing: it says the enclosing route group already
            // names this surface, so the sub-surface's names are a bare `filters.<verb>` the group
            // prefixes. `null` (nothing passed) falls back to the resource key.
            $stem = $names ?? ($resource ?? 'frame.resources');
            $name = $stem === '' ? 'filters' : $stem.'.filters';

            $mount = function (RouteInstance $route) use ($config, $middleware, $resource): RouteInstance {
                $route->defaults(ResourceFiltersController::CONFIG, $config);

                // ALSO stamp the ordinary particle resource default (ticket 01), so the group-resolution
                // chain sees a filter route exactly as it sees any other sub-operation of the resource
                // and the sub-surface inherits its resource's documentation group with nothing declared.
                // This is the same trick `Route::resourceRenderings()` leans on — the export routes' glob
                // was retired from the host's backlog because the route gained this stamp.
                //
                // Skipped for the frame-root mount, where the resource is a path parameter: there is no
                // one resource to stamp, and a stamp naming `{resource}` would be a lie the chain would
                // then try to resolve.
                if ($resource !== null) {
                    $route->defaults(ParticleController::RESOURCE, $resource);
                }

                if ($middleware !== []) {
                    $route->middleware($middleware);
                }

                return $route;
            };

            // ORDER IS LOAD-BEARING. `schema`, `variants` and `options/{ref}` are literal segments that
            // would otherwise be swallowed by `{id}`. The uuid constraint below makes that impossible
            // as well, but relying on a constraint alone would break the moment a host mounts with
            // `idConstraint: null` — so the literals are declared first AND constrained.
            $mount($this->get($prefix.'/schema', [ResourceFiltersController::class, 'schema']))
                ->name($name.'.schema');

            $mount($this->get($prefix.'/variants', [ResourceFiltersController::class, 'variants']))
                ->name($name.'.variants')
                ->beam()->returns(ResourceFilterVariantsData::class);

            $mount($this->get($prefix.'/options/{ref}', [ResourceFiltersController::class, 'options']))
                ->name($name.'.options');

            $mount($this->get($prefix.'/{variant}/schema', [ResourceFiltersController::class, 'variantSchema']))
                ->name($name.'.variant-schema');

            $mount($this->get($prefix, [ResourceFiltersController::class, 'index']))
                ->name($name.'.index');

            $mount($this->post($prefix, [ResourceFiltersController::class, 'store']))
                ->name($name.'.store');

            $withId = function (RouteInstance $route) use ($mount, $idConstraint): RouteInstance {
                $route = $mount($route);

                return $idConstraint === 'uuid' ? $route->whereUuid('id') : $route;
            };

            $withId($this->get($prefix.'/{id}', [ResourceFiltersController::class, 'show']))
                ->name($name.'.show');

            $withId($this->match(['put', 'patch'], $prefix.'/{id}', [ResourceFiltersController::class, 'update']))
                ->name($name.'.update');

            $withId($this->delete($prefix.'/{id}', [ResourceFiltersController::class, 'destroy']))
                ->name($name.'.destroy');
        });
    }
}
