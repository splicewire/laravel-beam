<?php

namespace Splicewire\Beam\Ia;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Which cross-instance sides this host plays, and the one way to mount a side's surface (ux-walkthrough M6, IA-6).
 * Dependency-free on purpose: D5′ macros call it at route registration, where resolving {@see HostIa} would build the
 * realm projector and need an entitlement resolver a route file has no business requiring. {@see HostIa::plays()} and
 * {@see HostIa::serve()} answer the same through here.
 */
final class Sides
{
    /**
     * Whether this host plays `$side`. `beam.core.ia.plays` lists the sides; undeclared (null) plays every side, so a
     * host that has not declared is unchanged.
     */
    public static function plays(Side $side): bool
    {
        $plays = config('beam.core.ia.plays');

        return $plays === null || in_array($side->value, (array) $plays, true);
    }

    /**
     * Mount a cross-instance surface: register `$routes`, each tagged with the side it serves (the route action's
     * `side`, which the host IA seam's T4 reads), or throw {@see SideRefused} when this host does not play that side.
     */
    public static function serve(Side $side, string $surface, Closure $routes): void
    {
        if (! self::plays($side)) {
            throw SideRefused::for($side, $surface, array_values((array) config('beam.core.ia.plays', [])));
        }

        Route::group(['side' => $side->value], $routes);
    }
}
