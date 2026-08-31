<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Splicewire\Beam\Discovery\Data\ResourceDiscoveryData;

/**
 * Can THIS caller reach THIS route? — the per-route gate that makes the listing a reachability answer
 * rather than a second copy of the reference (41 D1, D6).
 *
 * ## Why per-route, and never a union or an intersection
 *
 * Ticket 33's third note asked whether a converged listing should report the union of its sub-surfaces'
 * abilities or their intersection, and 41 dissolved the question rather than picking: neither, because
 * the listing is derived from ROUTES and every route already carries its own gate. A caller who may
 * read `activity` but not administer `open-api-specs` gets both facts from the same endpoint without
 * either being aggregated.
 *
 * ## Existence, not invocability — the caveat is the contract
 *
 * `POST /circuits/{id}/run` authorizes against a specific circuit, and a listing mounted at
 * `/circuits/discovery` has no `{id}` to authorize against. So an instance-scoped gate — `can:update,
 * circuit`, a policy behind a bound model — is NOT evaluated here and the route is listed. A caller may
 * therefore see an operation listed and still be refused on invoke. That is stated on the endpoint and
 * in {@see ResourceDiscoveryData} rather than papered over,
 * because the alternative — omitting every instance-scoped operation — would make the listing useless
 * for the surface it exists to describe.
 */
class RouteReachability
{
    /** @var array<string, ReachabilityProbe|null> */
    protected array $resolved = [];

    /**
     * @param  array<string, class-string<ReachabilityProbe>|ReachabilityProbe>  $probes
     */
    public function __construct(
        protected Container $container,
        protected Gate $gate,
        protected array $probes = [],
    ) {}

    public function allows(Route $route, ?Authenticatable $user): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $parameters] = $this->split($middleware);

            if (! $this->passes($name, $parameters, $route, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The two framework spellings beam reads for itself, then the host's probes, then the honest
     * default.
     *
     * `can:` with a SECOND argument names a model — `can:update,circuit` — and that is the instance
     * question the listing cannot ask. It passes here on purpose; see the class docblock.
     *
     * @param  list<string>  $parameters
     */
    protected function passes(string $name, array $parameters, Route $route, ?Authenticatable $user): bool
    {
        if ($name === 'auth' || str_starts_with($name, 'auth:')) {
            return $user !== null;
        }

        if ($name === 'can') {
            if ($parameters === [] || count($parameters) > 1) {
                return true;
            }

            // A `can:` gate with no user is a denial in Laravel's own Authorize middleware too; asking
            // the Gate for a null user is the thing that would throw.
            return $user !== null && $this->gate->forUser($user)->allows($parameters[0]);
        }

        $probe = $this->probe($name);

        return $probe === null || $probe->allows($user, $parameters, $route);
    }

    protected function probe(string $name): ?ReachabilityProbe
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        $declared = $this->probes[$name] ?? null;

        return $this->resolved[$name] = match (true) {
            $declared instanceof ReachabilityProbe => $declared,
            is_string($declared) && class_exists($declared) => $this->container->make($declared),
            default => null,
        };
    }

    /**
     * `entitlement:composition-engine` → `['entitlement', ['composition-engine']]`.
     *
     * Class-string middleware (`App\Http\Middleware\ResolveTenantUser`) has no colon and therefore no
     * parameters, which is why the split is unconditional rather than guarded on an alias list.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function split(string $middleware): array
    {
        if (! str_contains($middleware, ':')) {
            return [$middleware, []];
        }

        [$name, $arguments] = explode(':', $middleware, 2);

        return [$name, array_values(array_filter(explode(',', $arguments), fn ($a) => $a !== ''))];
    }
}
