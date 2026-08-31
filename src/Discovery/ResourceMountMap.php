<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Routing\RouteMetadataReader;

/**
 * **The route table is the join** (api-surface-coherence 41, built by 105).
 *
 * The re-appraisal that led to 41 priced the converged listing as a union over N registries and found
 * it blocked by the registry kernel's no-wildcard-root constraint (`beam.particle.*.ops` does not
 * exist). That priced the WRONG join. Every sub-surface already stamps its resource onto its own
 * routes, so the route table is a join that has already happened: one existing function
 * ({@see RouteMetadataReader::resourceKey()}), in memory, no cross-registry union anywhere.
 *
 * The population that falls out is not the same set as "the declared resources", and both directions of
 * the difference are load-bearing (measured on the flagship, 2026-08-28):
 *
 * - **Four stamped keys are not declared at all** — `disclosures`, `guest-links`, `market-products`,
 *   `releases`. A registry union would publish no listing for routes that demonstrably exist.
 * - **Thirteen declared keys have no stamped route** — `invitations`, `concepts`, `conduits`, `teams`,
 *   `me`, `tenants`, … A registry union would invent thirteen listings with nothing in them.
 *
 * A route-table read gets both right by construction, which is why 105's acceptance says *"all keys
 * with a stamped route"* rather than *"all registered resources"*.
 *
 * ## Deriving the mount root
 *
 * A mount is not stored anywhere — `Particle::mount('circuits')` leaves no artifact behind but its
 * routes — so the root is RECOVERED from each route by removing its own sub-surface tail. That step is
 * sub-surface-aware rather than a longest-common-prefix over the key's URIs, and it has to be. The
 * clearest case is the one it was built against, BEFORE particle-operation-surface 12: every one of
 * `market-products`' four routes was
 * `api/operator/beam-market/review/market-products/{id}/op/{name}`, whose longest common prefix ends at
 * `…/{id}/op` — and `op` is a LITERAL, which the trailing-parameter strip cannot remove. Knowing the
 * route is an OPERATION is what recovers `…/market-products`.
 *
 * ⚠️ The drop did not retire that need, it MOVED it, so do not read the example above as today's URL.
 * The primary spelling is now `…/market-products/{id}/{name}` (the deprecated alias still carries the
 * old one, and {@see rootOf()} branches on both). There the tail is the operation's own name and is
 * dropped by IDENTITY rather than by position — see the ⚠️ in `rootOf()`, where a resource whose ONLY
 * mounted route is an operation is precisely the case a prefix-and-strip gets wrong.
 *
 * Roots that nest then absorb into their shortest ancestor, which is what folds `api/v1/beam/schemas`,
 * `…/schemas/freeze` and `…/schemas/{stem}/latest` into one mount instead of four — and leaves
 * genuinely separate mounts (`api/v1/hooks` vs `api/operator/hooks`) standing as two.
 */
class ResourceMountMap
{
    public function __construct(
        protected Router $router,
        protected RouteMetadataReader $meta,
    ) {}

    /**
     * Every mount with at least one stamped route, keyed by nothing — a flat list, because a resource
     * may legitimately appear more than once.
     *
     * @return list<ResourceMount>
     */
    public function mounts(): array
    {
        $byResource = [];

        foreach ($this->router->getRoutes() as $route) {
            $resource = $this->meta->resourceKey($route);

            if ($resource === null) {
                continue;
            }

            // The listing's own routes are not part of the population that DEFINES a mount. Left in,
            // the map would re-derive the same mounts from its own output on a second pass — harmless
            // today, and a trap the moment someone mounts a listing by hand at a root of their own.
            if (SubSurface::of($route) === SubSurface::DISCOVERY) {
                continue;
            }

            $byResource[$resource][$this->rootOf($route)][] = $route;
        }

        ksort($byResource);

        $mounts = [];

        foreach ($byResource as $resource => $byRoot) {
            foreach ($this->absorbNestedRoots(array_keys($byRoot)) as $root) {
                $routes = [];

                foreach ($byRoot as $candidate => $list) {
                    if ($candidate === $root || str_starts_with($candidate, $root.'/')) {
                        $routes = array_merge($routes, $list);
                    }
                }

                $mounts[] = new ResourceMount(
                    resource: $resource,
                    root: $root,
                    nameStem: $this->nameStem($resource, $routes),
                    routes: array_values($routes),
                    middleware: $this->commonMiddleware($routes),
                );
            }
        }

        return $mounts;
    }

    /**
     * The URI a route hangs off, with its own sub-surface tail removed.
     *
     * The trailing-parameter strip at the end is shared by every arm and is what turns `…/circuits/{id}`
     * into `…/circuits`. It is a WHILE rather than an IF only so a two-parameter tail cannot survive it;
     * no shipped shape needs the second turn.
     */
    protected function rootOf(Route $route): string
    {
        $segments = explode('/', trim($route->uri(), '/'));
        $defaults = $route->defaults;

        if (isset($defaults[ParticleOperationController::NAME])) {
            // Two spellings, because particle-operation-surface 12 dropped the `/op/` segment and left
            // the old URL mounted as a deprecated alias. The alias still carries it and slices on the
            // word; the PRIMARY is `{root}/{id}/{name}`, where the tail is the operation's own name and
            // is dropped by identity rather than by position — `sliceBeforeLast` on a word that is no
            // longer there returns the segments UNCHANGED, so the trailing-parameter strip below never
            // reached `{id}` and the root came back as `{root}/{id}/{name}`.
            //
            // ⚠️ This was latent from 12 until particle-operation-surface 13. It only shows on a
            // resource whose ONLY mounted route is an operation: every other resource has a CRUD or
            // filter route computing the same root correctly, and `absorbNestedRoots()` folds the wrong
            // one into it. 13 made `disclosures` exactly that resource — its export became an operation
            // and it mounts nothing else — and `disclosures.discovery` moved from
            // `api/v1/disclosures/discovery` to `api/v1/disclosures/{id}/export/discovery`.
            $name = (string) $defaults[ParticleOperationController::NAME];

            $segments = in_array('op', $segments, true)
                ? $this->sliceBeforeLast($segments, 'op')
                : $this->sliceBeforeLast($segments, $name);
        } elseif (SubSurface::of($route) === SubSurface::EVENTS) {
            $segments = $this->sliceBeforeLast($segments, 'hooks');
        } elseif (isset($defaults[ResourceFiltersController::CONFIG])) {
            $segments = $this->sliceBeforeLast($segments, 'filters');
        }

        while ($segments !== [] && str_starts_with((string) end($segments), '{')) {
            array_pop($segments);
        }

        return implode('/', $segments);
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    protected function sliceBeforeLast(array $segments, string $needle): array
    {
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if ($segments[$i] === $needle) {
                return array_slice($segments, 0, $i);
            }
        }

        return $segments;
    }

    /**
     * Fold a root into its shortest ancestor. Shallowest first, so an ancestor is always decided before
     * anything that could nest under it.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    protected function absorbNestedRoots(array $roots): array
    {
        usort($roots, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));

        $kept = [];

        foreach ($roots as $root) {
            foreach ($kept as $ancestor) {
                if ($root === $ancestor || str_starts_with($root, $ancestor.'/')) {
                    continue 2;
                }
            }

            $kept[] = $root;
        }

        return $kept;
    }

    /**
     * The route-name stem the listing inherits — the mount's own longest common name prefix, truncated
     * at the stamp.
     *
     * **Inherited, not derived from the stamp, and that is the same rule 106 landed on.** The two hook
     * catalogs it flagged (`thread.hooks.events` on stamp `threads`, `statuses.hooks.events` on stamp
     * `model-statuses`) take their stem from their hand-written index route's own name, "exactly as
     * their filter siblings already do (`thread.filters.*`)". A listing that named itself
     * `threads.discovery` beside `thread.index` and `thread.filters.*` would introduce the drift rather
     * than inherit it.
     *
     * The truncation at the stamp is what stops `plans.op.checkout` — the only named route on that
     * mount — from yielding `plans.op.checkout.discovery`. Falls back to the stamp when the mount's
     * routes share no name at all (one unnamed route is enough to empty the intersection).
     *
     * @param  list<Route>  $routes
     */
    protected function nameStem(string $resource, array $routes): string
    {
        $common = null;

        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $segments = $name === '' ? [] : explode('.', $name);

            if ($common === null) {
                $common = $segments;

                continue;
            }

            $shared = [];

            foreach ($common as $i => $segment) {
                if (($segments[$i] ?? null) !== $segment) {
                    break;
                }

                $shared[] = $segment;
            }

            $common = $shared;
        }

        $common ??= [];

        $stamp = array_search($resource, $common, true);

        if ($stamp !== false) {
            $common = array_slice($common, 0, $stamp + 1);
        }

        return $common === [] ? $resource : implode('.', $common);
    }

    /**
     * Middleware every route in the mount carries.
     *
     * The listing route is mounted OUTSIDE the group that produced the mount — a boot-time pass over the
     * finished route table has no group to join — so it would otherwise ship unauthenticated beside
     * routes behind `auth:sanctum` and a tenancy stack. Taking the INTERSECTION rather than one
     * representative route's stack is the conservative reading of "reaching this mount at all": a
     * middleware only some of the mount's routes carry gates those routes, and the listing reports them
     * per-route through {@see RouteReachability} instead.
     *
     * @param  list<Route>  $routes
     * @return list<string>
     */
    protected function commonMiddleware(array $routes): array
    {
        $common = null;

        foreach ($routes as $route) {
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            $common = $common === null
                ? $middleware
                : array_values(array_intersect($common, $middleware));
        }

        return $common ?? [];
    }
}
