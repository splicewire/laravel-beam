<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Routing\BeamRouteProxy;

/**
 * The `->beam()` route-metadata namespace — one macro on the route INSTANCE carrying every beam
 * declaration a mounted route can make (api-surface-coherence ticket 15).
 *
 * A link in {@see BeamServiceProvider}'s `boot` chain rather than a line in its `packageBooted()`: the
 * provider no longer keeps a hand-written index of its own parts, and adding a concern is `use`-ing it.
 */
trait BootsBeamRouteNamespace
{
    /**
     * Register the `->beam()` route-metadata namespace (api-surface-coherence ticket 15).
     *
     * Note this hangs off the route INSTANCE ({@see RouteInstance}), not the {@see Route} facade's Router
     * — the distinction the bare macros already drew and the reason they can't live next to
     * `particleResource`. `particleResource` MOUNTS routes, so it is a Router call; `->beam()` DECORATES a
     * route that already exists, so it is a Route call and only makes sense post-mount.
     *
     * Everything the namespace can declare lives on {@see BeamRouteProxy}; adding a fifth declaration is a
     * method there and touches nothing here. That is the point of the namespace — the global macro table
     * stops growing one entry per beam concept, so `Macroable::macro()`'s silent overwrite has exactly one
     * surface to hit instead of four. `BeamRouteNamespaceTest` asserts `->beam()` still returns OUR proxy,
     * which is what turns a foreign overwrite from invisible into a red test.
     */
    #[Chained('boot', order: 20)]
    protected function bootBeamRouteNamespace(): void
    {
        if (RouteInstance::hasMacro('beam')) {
            return;
        }

        RouteInstance::macro('beam', function (): BeamRouteProxy {
            /** @var RouteInstance $this */
            return new BeamRouteProxy($this);
        });
    }
}
