<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Routing\Route;
use Splicewire\Beam\Discovery\Http\ResourceDiscoveryController;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

/**
 * Which sub-surface of its resource a route belongs to (api-surface-coherence 105).
 *
 * Read off the route's own DEFAULTS, never off its URI or its name. That is the whole reason ticket 41
 * D5 could price the listing as a route-table read: the four sub-surfaces already stamp themselves at
 * mount time, so classifying a route is a lookup rather than a parse. A URI parse would have to know
 * that `api/v1/statuses` is `model-statuses` and that `guest-tokens` is `guest-links` — divergences
 * ticket 10 §1 measured at roughly half the estate and ruled legitimate.
 *
 * `CRUD` is the fallback rather than a fifth stamp, and deliberately: a route that carries the
 * `_particle` stamp and none of the sub-surface configs IS the resource's own surface. Adding a stamp
 * to say so would make every hand-written `->beam()->inResource()` route in the estate a conformance
 * failure overnight for no reader's benefit.
 */
class SubSurface
{
    public const CRUD = 'crud';

    public const FILTERS = 'filters';

    public const OPERATIONS = 'operations';

    public const EVENTS = 'events';

    public const DISCOVERY = 'discovery';

    /** Every sub-surface a listing can report, in the order a reader wants them. */
    public const ALL = [
        self::CRUD,
        self::OPERATIONS,
        self::FILTERS,
        self::EVENTS,
        self::DISCOVERY,
    ];

    public static function of(Route $route): string
    {
        $defaults = $route->defaults;

        return match (true) {
            isset($defaults[ResourceDiscoveryController::CONFIG]) => self::DISCOVERY,
            isset($defaults[ParticleOperationController::NAME]) => self::OPERATIONS,
            isset($defaults[ResourceFiltersController::CONFIG]) => self::FILTERS,
            self::servedBy($route, HookEventCatalogController::class) => self::EVENTS,
            default => self::CRUD,
        };
    }

    /**
     * The hook-event catalog is the one sub-surface with no config default of its own on the SCOPED
     * mount — 106 stamped it with the ordinary `_particle` resource default precisely so
     * {@see BeamRouteAction::resourceKey()} would not grow a fifth arm. So it
     * is recognised by its controller, which is closed: every route on that class came out of
     * {@see ParticleMounter::resourceHookEvents()}.
     */
    protected static function servedBy(Route $route, string $controller): bool
    {
        $action = strtok((string) $route->getActionName(), '@');

        return is_string($action) && class_exists($action) && is_a($action, $controller, true);
    }
}
