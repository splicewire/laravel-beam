<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Discovery\RouteReachability;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ActorSubject;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Throwable;

/**
 * The route-derived answer to “may this principal see this seat?”.
 *
 * A seat carries no permission declaration of its own. Its route resolves, in order, to the
 * resource-list gate, the declared particle-operation gate, an explicit open decision, or a named
 * middleware gate. A missing decision stays missing so I6 can reject host-authored navigation and
 * prune package navigation instead of silently treating an omission as public.
 */
class SeatGate
{
    /** A bespoke route's reviewed decision that authentication alone is sufficient. */
    public const OPEN_TO_MEMBERS = '_beam_open_to_members';

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
        private ParticleOperationRegistry $operations,
        private ResourceVisibility $visibility,
        private AbilityResolver $abilities,
        private RouteReachability $reachability,
    ) {}

    public function resolve(string $routeName, ?string $realm = null): ?SeatGateResolution
    {
        $routes = $this->router->getRoutes();
        $route = $routes->getByName($routeName);

        // A package or test may add a route after Laravel last refreshed the collection's name lookup.
        // The route table itself is live; a stale convenience index must not make a mounted gate look
        // undeclared until the next framework refresh.
        if (! $route instanceof Route) {
            $route = collect($routes->getRoutes())->first(
                fn (Route $candidate): bool => $candidate->getName() === $routeName,
            );
        }

        return $route instanceof Route ? $this->resolveRoute($route, $realm) : null;
    }

    public function resolveRoute(Route $route, ?string $realm = null): ?SeatGateResolution
    {
        $operationResource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $operationName = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (is_string($operationResource) && is_string($operationName)) {
            $operation = $this->operations->find($operationResource, $operationName);

            if ($operation === null || $operation->ability === null || ! $this->operationCanResolve($operation)) {
                return null;
            }

            return new SeatGateResolution(
                $operation->ability === false ? SeatGateKind::Open : SeatGateKind::Operation,
                $route,
                operation: $operation,
            );
        }

        $resourceKey = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (is_string($resourceKey)) {
            try {
                $resource = $this->resources->definition($resourceKey, $realm);
            } catch (Throwable) {
                $resource = null;
            }

            if ($resource !== null && $route->getName() === ListRouteName::of($resource)) {
                return new SeatGateResolution(SeatGateKind::Resource, $route, resource: $resource);
            }
        }

        if (($route->defaults[self::OPEN_TO_MEMBERS] ?? false) === true) {
            return new SeatGateResolution(SeatGateKind::Open, $route);
        }

        return $this->reachability->declaresGate($route)
            ? new SeatGateResolution(SeatGateKind::Route, $route)
            : null;
    }

    public function for(string $routeName, ?Authenticatable $actor, ?string $realm = null): bool
    {
        $resolution = $this->resolve($routeName, $realm);

        return $resolution !== null && $this->allows($resolution, $actor);
    }

    public function allows(SeatGateResolution $resolution, ?Authenticatable $actor): bool
    {
        if (! $this->reachability->allows($resolution->route, $actor)) {
            return false;
        }

        return match ($resolution->kind) {
            SeatGateKind::Resource => $resolution->resource !== null
                && $this->visibility->listable($resolution->resource, $actor),
            SeatGateKind::Operation => $this->operationAllows($resolution->operation, $actor),
            SeatGateKind::Route, SeatGateKind::Open => true,
        };
    }

    /** A record-bound operation cannot be decided without a record and therefore is not a rail gate. */
    private function operationCanResolve(ParticleOperation $operation): bool
    {
        if ($operation->ability === false || $operation->abilityModel === false || is_string($operation->abilityModel)) {
            return true;
        }

        $subject = $operation->subject;

        return $subject instanceof NoSubject
            || $subject instanceof ActorSubject
            || (is_string($subject) && (is_a($subject, NoSubject::class, true) || is_a($subject, ActorSubject::class, true)));
    }

    private function operationAllows(?ParticleOperation $operation, ?Authenticatable $actor): bool
    {
        if ($operation === null || ! is_string($operation->ability)) {
            return false;
        }

        $subject = match (true) {
            $operation->abilityModel === false => null,
            is_string($operation->abilityModel) => $operation->abilityModel,
            $operation->subject instanceof ActorSubject => $actor,
            is_string($operation->subject) && is_a($operation->subject, ActorSubject::class, true) => $actor,
            default => null,
        };

        return $this->abilities->allows($actor, $operation->ability, $subject);
    }
}
