<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Schemastud\Frame\Http\Controllers\FrameResourceFiltersController;
use Schemastud\Frame\Http\Controllers\FrameResourceSummaryController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\RuntimeCorroborator;

/**
 * ADR-0230 — counts a resource a guest can REACH that declares no Public Read.
 *
 * Since ADR-0230 the read guard refuses a guest ({@see \Splicewire\Beam\Authorization\ResourceReadGuard})
 * any resource that declares no `publicRead:`, on every read path. A GET read route mounted WITHOUT an
 * authenticating middleware on such a resource is therefore negative space, not a hole: the mount invites a
 * guest the guard will refuse. Either the resource should declare a Public Read (and admit the guest to it),
 * or the mount should authenticate. This audit reports the residue, counted and warn-level on the ADR-0223
 * schedule ("undeclared is counted, loudly, until zero"), never gating — the mirror of
 * {@see UndeclaredAffordanceAudit} for reads, and the read-side sibling of
 * {@see ScopedReadAuthenticationAudit}.
 */
class GuestReadablePublicReadAudit implements DoctorAudit
{
    public const CHECK = 'resource.read.guest-public-read';

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
    ) {}

    public static function forApp(): self
    {
        return new self(app(Router::class), app(ParticleResourceRegistry::class));
    }

    /**
     * The registered resources that declare NO Public Read — the population this check measures reachability
     * against. (Not every one is guest-reachable; {@see run()} intersects it with unauthenticated routes.)
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->resources->all() as $resource) {
            if (! $resource->declaresPublicRead()) {
                $keys[] = $resource->key;
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $readRoutes = $this->readRoutes();
        $frameRoutes = $this->genericFrameReadRoutes();

        if ($readRoutes === [] && $frameRoutes === []) {
            return [Finding::inconclusive(
                self::CHECK,
                'No particle resource GET read route is registered in this host, so no guest reachability was measured.',
            )];
        }

        $withoutPublicRead = $this->keys();
        $reachable = [];

        foreach ($readRoutes as $key => $routes) {
            if (! in_array($key, $withoutPublicRead, true)) {
                continue;
            }

            foreach ($routes as $route) {
                if (! $this->authenticating($route)) {
                    $reachable[$key] = true;
                }
            }
        }

        // The generic Frame resource socket serves every registered resource; an unauthenticated one reaches
        // every Public-Read-less resource at once, exactly as ScopedReadAuthenticationAudit reads it.
        $viaFrameSocket = false;
        foreach ($frameRoutes as $route) {
            if (! $this->authenticating($route)) {
                $viaFrameSocket = true;
            }
        }
        if ($viaFrameSocket) {
            foreach ($withoutPublicRead as $key) {
                $reachable[$key] = true;
            }
        }

        $keys = array_keys($reachable);
        sort($keys);

        if ($keys === []) {
            return [Finding::pass(
                self::CHECK,
                $withoutPublicRead === []
                    ? 'Every registered particle resource declares a Public Read (ADR-0230).'
                    : 'No guest-reachable particle resource declares without a Public Read; every unauthenticated read route is on a resource that declares one.',
            )];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d particle resource%s a guest can reach declare%s no Public Read (ADR-0230) — the read guard '
            ."refuses a guest there; declare `publicRead:` to admit the guest, or authenticate the mount:\n%s",
            count($keys),
            count($keys) === 1 ? '' : 's',
            count($keys) === 1 ? 's' : '',
            implode("\n", array_map(fn (string $key): string => '  '.$key, $keys)),
        ))];
    }

    /** @return array<string, list<RouteInstance>> */
    private function readRoutes(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $key = $route->defaults[ParticleController::RESOURCE] ?? null;
            if (! is_string($key) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $routes[$key][] = $route;
        }

        return $routes;
    }

    /** @return list<RouteInstance> */
    private function genericFrameReadRoutes(): array
    {
        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            fn (RouteInstance $route): bool => in_array('GET', $route->methods(), true)
                && $this->dispatchesGenericFrameResource($route),
        ));
    }

    private function dispatchesGenericFrameResource(RouteInstance $route): bool
    {
        $controller = explode('@', $route->getActionName(), 2)[0];
        if (! class_exists($controller)) {
            return false;
        }

        foreach ([
            FrameResourceController::class,
            FrameResourceFiltersController::class,
            FrameResourceSummaryController::class,
        ] as $socket) {
            if (is_a($controller, $socket, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the route AUTHENTICATE or AUTHORIZE the request — the vocabulary ADR-0230 names (auth, can:,
     * Sanctum, verified, signed, and the host `require.*` permission families)? Read through the one matcher
     * the read gate uses ({@see RuntimeCorroborator::middlewareMatches()}), so this audit and the gate cannot
     * disagree about what an authenticating signal is. A tenancy initializer is NOT authentication.
     */
    private function authenticating(RouteInstance $route): bool
    {
        $middleware = [...$route->middleware(), ...$this->router->gatherRouteMiddleware($route)];

        if (RuntimeCorroborator::middlewareMatches($middleware, [
            ...RuntimeCorroborator::DEFAULT_AUTH_MIDDLEWARE,
            ...RuntimeCorroborator::DEFAULT_AUTHORIZATION_MIDDLEWARE,
            'verified',
            'signed',
        ])) {
            return true;
        }

        // `require.*` host permission aliases are not class-strings the matcher carries; match the prefix.
        foreach ($middleware as $entry) {
            if (is_string($entry) && str_starts_with(ltrim($entry, '\\'), 'require.')) {
                return true;
            }
        }

        return false;
    }
}
