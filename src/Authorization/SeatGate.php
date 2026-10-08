<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
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
    /** The hidden nav-node meta key carrying a {@see SeatGateResolution}. */
    public const META = 'beam.seat_gate';

    /** A bespoke route's reviewed decision that authentication alone is sufficient. */
    public const OPEN_TO_MEMBERS = '_beam_open_to_members';

    /** @var array<string, string> SPA/client seat identity => backing data-route name. */
    private array $backings = [];

    /** @var array<string, true> reviewed seats for which authenticated membership is sufficient. */
    private array $openSeats = [];

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
        private ParticleOperationRegistry $operations,
        private ResourceVisibility $visibility,
        private AbilityResolver $abilities,
        private RouteReachability $reachability,
    ) {}

    /** Declare the one data route whose authorization decision governs a client-side seat. */
    public function backedBy(string $seatRouteName, string $backingRouteName): void
    {
        if ($seatRouteName === '' || $backingRouteName === '' || $seatRouteName === $backingRouteName) {
            throw new LogicException('A seat gate backing must name two distinct, non-empty routes.');
        }

        if (isset($this->openSeats[$seatRouteName])) {
            throw new LogicException("Seat [{$seatRouteName}] already declares an explicit open gate.");
        }

        if (isset($this->backings[$seatRouteName]) && $this->backings[$seatRouteName] !== $backingRouteName) {
            throw new LogicException("Seat [{$seatRouteName}] already resolves from [{$this->backings[$seatRouteName]}].");
        }

        $cursor = $backingRouteName;
        while (isset($this->backings[$cursor])) {
            $cursor = $this->backings[$cursor];
            if ($cursor === $seatRouteName) {
                throw new LogicException("Seat gate backing [{$seatRouteName}] forms a cycle.");
            }
        }

        $this->backings[$seatRouteName] = $backingRouteName;
    }

    /** Declare, case by case, that the authenticated tenant boundary is this seat's whole gate. */
    public function openToMembers(string $seatRouteName): void
    {
        if ($seatRouteName === '') {
            throw new LogicException('An explicit open seat must have a route name.');
        }

        if (isset($this->backings[$seatRouteName])) {
            throw new LogicException("Seat [{$seatRouteName}] already resolves from [{$this->backings[$seatRouteName]}].");
        }

        $this->openSeats[$seatRouteName] = true;
    }

    public function resolve(string $routeName, ?string $realm = null): ?SeatGateResolution
    {
        if (isset($this->openSeats[$routeName])) {
            return new SeatGateResolution(SeatGateKind::Open, null);
        }

        if (isset($this->backings[$routeName])) {
            return $this->resolve($this->backings[$routeName], $realm);
        }

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

        if ($route instanceof Route) {
            return $this->resolveRoute($route, $realm);
        }

        // Frame's routeName is the stable client join and a host may mount the generic resource
        // socket under a different (or unnamed) HTTP route. The resource declaration still owns the
        // list leaf's gate, so resolve that arm directly from the realm-projected catalog.
        try {
            foreach ($this->resources->definitions($realm) as $resource) {
                if (ListRouteName::of($resource) === $routeName) {
                    return new SeatGateResolution(SeatGateKind::Resource, null, resource: $resource);
                }
            }
        } catch (Throwable) {
            // An unprojectable catalog is unresolved; I6 owns the refusal shape.
        }

        return null;
    }

    public function resolveRoute(Route $route, ?string $realm = null): ?SeatGateResolution
    {
        $resolutions = [];
        $operationResource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $operationName = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (is_string($operationResource) && is_string($operationName)) {
            $operation = $this->operations->find($operationResource, $operationName);

            if ($operation !== null && $operation->ability !== null && $this->operationCanResolve($operation)) {
                $resolutions[] = new SeatGateResolution(
                    $operation->ability === false ? SeatGateKind::Open : SeatGateKind::Operation,
                    $route,
                    operation: $operation,
                );
            }
        }

        $resourceKey = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (is_string($resourceKey)) {
            $declaration = $this->resources->find($resourceKey);
            $resource = $declaration?->toResourceDefinition($realm);

            if ($resource !== null && $route->getName() === ListRouteName::of($resource)) {
                $resolutions[] = new SeatGateResolution(SeatGateKind::Resource, $route, resource: $resource);
            }
        }

        if (($route->defaults[self::OPEN_TO_MEMBERS] ?? false) === true) {
            $resolutions[] = new SeatGateResolution(SeatGateKind::Open, $route);
        }

        if ($this->reachability->declaresGate($route)) {
            $resolutions[] = new SeatGateResolution(SeatGateKind::Route, $route);
        }

        return count($resolutions) === 1 ? $resolutions[0] : null;
    }

    public function for(string $routeName, ?Authenticatable $actor, ?string $realm = null): bool
    {
        $resolution = $this->resolve($routeName, $realm);

        return $resolution !== null && $this->allows($resolution, $actor, $realm);
    }

    public function allows(SeatGateResolution $resolution, ?Authenticatable $actor, ?string $realm = null): bool
    {
        if ($resolution->route !== null && ! $this->reachability->allows($resolution->route, $actor)) {
            return false;
        }

        return match ($resolution->kind) {
            SeatGateKind::Resource => $this->resourceAllows($resolution, $actor),
            SeatGateKind::Operation => $this->operationAllows($resolution->operation, $actor),
            SeatGateKind::Route => true,
            SeatGateKind::Open => $actor !== null,
        };
    }

    /** Match the actual Frame list path: realm reach first, then the declaration-derived read boundary. */
    private function resourceAllows(SeatGateResolution $resolution, ?Authenticatable $actor): bool
    {
        $definition = $resolution->resource;
        if ($definition === null || ! $this->visibility->readable($definition, $actor)) {
            return false;
        }

        // A service-backed list has no model policy or declaration-derived query to ask. Its route
        // gate is the declared model-less read ability above plus the route middleware already
        // checked by allows().
        if ($definition->model === null) {
            return $actor !== null;
        }

        $resource = $this->resources->find($definition->key);

        return $resource !== null
            && ResourceReadGuard::forApp()->inspectReadFor($resource, request(), $actor)->allowed();
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
