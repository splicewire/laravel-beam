<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Schemastud\Frame\Http\Controllers\FrameResourceFiltersController;
use Schemastud\Frame\Http\Controllers\FrameResourceSummaryController;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Refuses public mounts whose only list-read boundary is a row scope. */
class ScopedReadAuthenticationAudit implements DoctorAudit
{
    public const CHECK = 'resource.read.scoped-authentication';

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
        private ResourceReadGuard $guard,
    ) {}

    public static function forApp(): self
    {
        return new self(app(Router::class), app(ParticleResourceRegistry::class), ResourceReadGuard::forApp());
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->resources->all() as $resource) {
            if ($this->scopeWithoutAuthority($resource)) {
                $keys[] = $resource->key;
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $keys = $this->keys();
        $unsafe = [];

        foreach ($this->readRoutes() as $key => $routes) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            foreach ($routes as $route) {
                if (! $this->authenticated($route)) {
                    $unsafe[] = sprintf('[%s] at GET %s', $key, $route->uri());
                }
            }
        }

        if ($keys !== []) {
            foreach ($this->genericFrameReadRoutes() as $route) {
                if (! $this->authenticated($route)) {
                    $unsafe[] = sprintf(
                        'generic Frame resource socket for [%s] at GET %s',
                        implode(', ', $keys),
                        $route->uri(),
                    );
                }
            }
        }

        if ($unsafe !== []) {
            sort($unsafe);

            return [Finding::fail(self::CHECK, sprintf(
                'A row scope is not caller authority. These scoped resources declare no read policy, ability, or model policy and are mounted without authentication: %s.',
                implode(', ', $unsafe),
            ))];
        }

        return [Finding::pass(self::CHECK, $keys === []
            ? 'No registered resource relies on a row scope without declared read authority.'
            : 'Every mounted route for the scoped resources without declared read authority is authenticated: '.implode(', ', $keys).'.')];
    }

    private function scopeWithoutAuthority(ParticleResource $resource): bool
    {
        return $this->guard->scoped($resource) === true
            && $resource->readPolicy === null
            && $this->guard->declaredReadAbility($resource) === null
            && $this->guard->policyBound($resource) !== true;
    }

    /** @return array<string, list<RouteInstance>> */
    private function readRoutes(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $key = $route->defaults[ParticleController::RESOURCE] ?? null;
            if (! is_string($key)
                || ! in_array('GET', $route->methods(), true)) {
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

    private function authenticated(RouteInstance $route): bool
    {
        foreach ([...$route->middleware(), ...$this->router->gatherRouteMiddleware($route)] as $entry) {
            $name = is_string($entry) ? $entry : (is_object($entry) ? $entry::class : '');
            $class = ltrim(explode(':', $name, 2)[0], '\\');

            if ($class === 'auth' || (class_exists($class) && is_a($class, Authenticate::class, true))) {
                return true;
            }
        }

        return false;
    }
}
