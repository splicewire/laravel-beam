<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Splicewire\Beam\Particle\Mount\ParticleMounter;

/**
 * Mount one `GET {mount}/discovery` per stamped mount, once the route table is finished
 * (api-surface-coherence 105, 41 D5).
 *
 * ## Idempotent, because "the route table is finished" is a host-dependent moment
 *
 * `booted()` is where beam can arm the pass, and on a plain host that is also where the table is
 * complete. It is not universal: the flagship registers its whole tenant surface — 294 of its routes —
 * from a `booted()` callback of its OWN (`TenancyServiceProvider::mapRoutes()`), and beam's provider
 * boots first, so beam's callback is appended first and fires first. Armed only there, the pass mounted
 * 5 of 35 listings and silently missed every `api/v1` resource.
 *
 * So `mount()` is re-runnable: it skips any mount whose listing is already up and adds the rest. A host
 * that grows its route table after boot calls it again at the point it knows the table changed — one
 * line, at the site that knows the fact — rather than beam guessing at a "late enough" hook that does
 * not exist.
 *
 * ## Why a boot-time pass and not a line in `resource()`
 *
 * 41 D5 says the listing mounts *wherever any stamped route for that key exists* — which is not the
 * same as *wherever a resource was mounted*. Three keys on the flagship have operations and no CRUD
 * root at all (`market-products` 4 ops, `plans` 1, `users` 1) and D3 refuses to invent roots for them
 * just to satisfy a listing. A hook inside `ParticleMounter::resource()` would miss all three, and
 * would double-mount for a resource whose routes are hand-written. The population is only knowable once
 * every provider has registered, so the pass runs on `Application::booted()` — the same point beam
 * already picked for the event catalog, and for the same reason.
 *
 * ## The reorder is load-bearing, not tidiness
 *
 * Laravel matches routes in registration order, and a pass that runs last registers last. Measured on
 * the flagship before the reorder existed: **14 of 35** discovery URIs were swallowed by an
 * already-registered `{root}/{id}` whose parameter carried no constraint — `api/v1/agents/discovery`
 * resolving to `agents.show` with `id=discovery`. So the finished collection is rebuilt with the
 * listing routes in FRONT. They are literal single-segment paths under an existing prefix, so moving
 * them forward can only take precedence from a parameter pattern — never from another literal route,
 * because a mount that already had a literal `discovery` child would have been found by the shadow
 * check as a name collision rather than a silent one.
 *
 * A host that has run `route:cache` gets a {@see CompiledRouteCollection}, which
 * cannot be reordered this way. That case is left alone rather than half-handled: the routes still
 * mount, and the ones a parameter route shadows are reported by the host's own conformance test rather
 * than silently mis-served.
 */
class ResourceDiscoveryAutoMounter
{
    public function __construct(
        protected Router $router,
        protected ResourceMountMap $map,
        protected ParticleMounter $mounter,
    ) {}

    public function mount(): void
    {
        $existing = $this->existing();
        $added = false;

        foreach ($this->map->mounts() as $mount) {
            if (isset($existing[$mount->uri()])) {
                continue;
            }

            $this->mounter->resourceDiscovery($this->router, $mount);
            $added = true;
        }

        if ($added) {
            $this->promote();
        }
    }

    /**
     * The listing routes already on the table, by URI — the memo that makes a second run cheap and a
     * third run free.
     *
     * @return array<string, Route>
     */
    protected function existing(): array
    {
        $existing = [];

        foreach ($this->router->getRoutes() as $route) {
            if (SubSurface::of($route) === SubSurface::DISCOVERY) {
                $existing[$route->uri()] = $route;
            }
        }

        return $existing;
    }

    /** Rebuild the route collection with every listing route first, preserving the order of the rest. */
    protected function promote(): void
    {
        $routes = $this->router->getRoutes();

        if ($routes::class !== RouteCollection::class) {
            return;
        }

        $promoted = $this->existing();

        if ($promoted === []) {
            return;
        }

        $reordered = new RouteCollection;

        foreach ($promoted as $route) {
            $reordered->add($route);
        }

        foreach ($routes as $route) {
            if (! in_array($route, $promoted, true)) {
                $reordered->add($route);
            }
        }

        $this->router->setRoutes($reordered);
    }
}
