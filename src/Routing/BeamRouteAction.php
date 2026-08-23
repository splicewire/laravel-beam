<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Rendering\Http\RenderingsController;

/**
 * The read side of the `->beam()` route-metadata namespace (api-surface-coherence ticket 15).
 *
 * Ticket 15's Q3 asked whether the ACTION KEYS should be namespaced as well as the method names, on the
 * grounds that the collision surface is arguably worse there — the keys were being read by raw string
 * from three places across two packages. They are namespaced, and this class is why that costs nothing:
 * every reader asks a named method instead of spelling `getAction('returns')`, so the storage layout is
 * an implementation detail of {@see BeamRouteProxy} and moving it again is a one-file edit.
 *
 * **The action keys are not the manifest keys.** `RouteManifest` emits `returns`/`returnsMany`/
 * `visibility`/`streams` at the TOP level of its JSON, and that JSON is a published wire contract —
 * `GET /v1/routes`, `api/operator/routes`, and the generated TypeScript client all consume it. Ticket 15
 * moved where the values are STORED on the route, never what the manifest CALLS them. If you are here to
 * rename something, that boundary is the thing to not cross.
 */
class BeamRouteAction
{
    /** The declared response DTO, or null when the route declares none. */
    public static function returns(Route $route): ?string
    {
        $value = static::get($route, 'returns');

        return is_string($value) ? $value : null;
    }

    /** Whether the declared response DTO is a list rather than a single instance. */
    public static function returnsMany(Route $route): bool
    {
        return (bool) static::get($route, 'returnsMany');
    }

    /**
     * The declared SSE event map, `event name => list of frame DTOs`. Empty when the route is not a
     * declared stream.
     *
     * @return array<string, list<class-string>>
     */
    public static function streams(Route $route): array
    {
        $value = static::get($route, 'streams');

        return is_array($value) ? $value : [];
    }

    /**
     * The declared exposure tier, or null when undeclared. Callers apply their own default — the manifest
     * treats undeclared as `internal`, and that opt-in-publicness policy belongs to the manifest, not here.
     */
    public static function visibility(Route $route): ?RouteVisibility
    {
        $value = static::get($route, 'visibility');

        return $value instanceof RouteVisibility ? $value : null;
    }

    /**
     * The resource key this route belongs to — whether stamped by `Route::particleResource()` or declared
     * by `->beam()->inResource()`. Both write the same route default, so this reader cannot tell them
     * apart, which is the point (ticket 01).
     *
     * An OPERATION route (`Route::particleOp()`/`particleOps()`) stamps its resource under a second key,
     * because the operation controller resolves the op by (resource, name) rather than serving the
     * resource's own CRUD. That is an implementation detail of the mount, not a second kind of belonging —
     * `POST /circuits/{id}/op/duplicate` belongs to `circuits` exactly as `GET /circuits` does — so this
     * reader falls through to it. Ticket 17 found this while wiring the group chain: the ticket's own note
     * claimed there was one stamp to read, and there are two.
     *
     * There are THREE. `Route::resourceRenderings()` stamps its whole per-route config under a third key,
     * and the resource is a field inside it — so `GET /disclosures/{id}/export` belongs to `disclosures`
     * on exactly ticket 01's argument, and reading it here is what let the hand-placed
     * "Renderings & Export" group be deleted rather than replaced (ticket 32 §F). A group of three
     * endpoints whose only commonality is a shared controller method is a placement, not a taxonomy.
     */
    public static function resourceKey(Route $route): ?string
    {
        $value = $route->defaults[ParticleController::RESOURCE]
            ?? $route->defaults[ParticleOperationController::RESOURCE]
            ?? ($route->defaults[RenderingsController::CONFIG]['resource'] ?? null);

        return is_string($value) ? $value : null;
    }

    protected static function get(Route $route, string $key): mixed
    {
        return $route->getAction(BeamRouteProxy::ACTION.'.'.$key);
    }
}
