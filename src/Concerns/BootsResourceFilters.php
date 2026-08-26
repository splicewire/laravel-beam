<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

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
            app(ParticleMounter::class)->resourceFilters(
                router: $this,
                resource: $resource,
                at: $at,
                names: $names,
                middleware: $middleware,
                idConstraint: $idConstraint,
            );
        });
    }
}
