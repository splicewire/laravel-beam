<?php

namespace Splicewire\Beam\Agreements\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * agreements 07 (lead ruling Q-07c): the `web` and `api` groups' one global entry, so a Requirement a provider attaches
 * to a route's name (`route:{name}`) gates that route without anyone editing it.
 *
 * Inert for every route nothing declares (root's condition): with no agreements seam it returns at once, and with one
 * it makes ONE port lookup for the route's behavior, never calls the resolver, never touches the database. Ordered after
 * authentication and route-model binding in the priority list (BeamServiceProvider), so a request auth refused never
 * reaches it, and a subject is the bound record.
 */
final class EnforceRouteRequirements
{
    public function __construct(private RouteRequirementGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->gate->seamed()) {
            return $next($request);
        }

        $route = $request->route();
        if (! $route instanceof Route || ! $this->gate->declaresRoute($route)) {
            return $next($request);
        }

        return $this->gate->handle($request, $next);
    }
}
