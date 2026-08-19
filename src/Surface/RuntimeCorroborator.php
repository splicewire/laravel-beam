<?php

namespace Splicewire\Beam\Surface;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\Data\RoutePostureData;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;

/**
 * Projects **security posture** off the live router and the particle registries — one
 * {@see RoutePostureData} per route.
 *
 * This is genuinely new ground next to {@see UndeclaredSurfaceAudit}. That audit asks whether a route
 * declares its *data shape*; it never asks whether the route is authenticated, gated, tenant-scoped, or
 * rate-limited. Same route table, orthogonal question — which is also why this class **consumes** that
 * audit for the negative-space direction (see {@see undeclared()}) instead of walking the table a second
 * time. Re-walking it would duplicate a deliberate design: the exemption list, the tiering, and the
 * closure handling are all decisions already made there, and a second copy would drift from them.
 *
 * ## The omission discipline
 *
 * Every facet is answered from evidence the router actually carries. When it does not carry the
 * evidence, the facet is **left out of the projection** rather than defaulted — see {@see PostureFacet}
 * for why both possible defaults are worse than silence. Concretely:
 *
 * - **Authentication and rate limiting are always determinable.** Both are middleware in this estate,
 *   and the resolved middleware stack is fully enumerable, so absence is real evidence of absence.
 * - **Authorization is determinable only on the particle pipeline or behind `can:`.** Off it, a gate may
 *   live in a FormRequest or in the action body, which no static walk can see — so the facet is omitted.
 * - **Tenancy is determinable only positively.** Beam's own doctrine is that the model doesn't know it's
 *   tenanted, the *connection* does; a route with no tenancy middleware may still resolve against a
 *   tenant connection. So a tenancy initializer proves `true` and its absence proves nothing.
 * - **Audit logging is determinable only positively**, for the same reason: it is a model-lifecycle
 *   concern, not a routing one, and the router cannot see it.
 *
 * The consequence is that a first run reports a lot of gaps. That is the honest number, and the cost of
 * making it smaller is a facet that lies.
 */
class RuntimeCorroborator
{
    /**
     * Middleware whose presence proves authentication. Matched as class/alias prefixes against the
     * RESOLVED stack, so `auth:sanctum` and `Illuminate\Auth\Middleware\Authenticate:sanctum` both hit.
     *
     * @var list<string>
     */
    public const DEFAULT_AUTH_MIDDLEWARE = [
        'auth',
        'auth.basic',
        'auth.session',
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities',
        'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility',
    ];

    /** @var list<string> */
    public const DEFAULT_AUTHORIZATION_MIDDLEWARE = [
        'can',
        'Illuminate\\Auth\\Middleware\\Authorize',
    ];

    /** @var list<string> */
    public const DEFAULT_TENANCY_MIDDLEWARE = [
        'tenant',
        'Stancl\\Tenancy\\Middleware\\',
        'Splicewire\\Beam\\Tenancy\\Middleware\\',
    ];

    /** @var list<string> */
    public const DEFAULT_RATE_LIMIT_MIDDLEWARE = [
        'throttle',
        'Illuminate\\Routing\\Middleware\\ThrottleRequests',
        'Illuminate\\Routing\\Middleware\\ThrottleRequestsWithRedis',
    ];

    /** @var list<string> */
    public const DEFAULT_AUDIT_MIDDLEWARE = [
        'Splicewire\\Beam\\Activity\\Middleware\\',
    ];

    public function __construct(
        private readonly Router $router,
        private readonly ParticleResourceRegistry $resources,
        private readonly ParticleOperationRegistry $operations,
        private readonly UndeclaredSurfaceAudit $undeclaredSurface,
        /** @var array<string, list<string>> */
        private readonly array $middlewareSignals = [],
    ) {}

    /**
     * The whole live surface's posture, keyed by normalized signature.
     *
     * @return array<string, RoutePostureData>
     */
    public function posture(): array
    {
        $postures = [];

        foreach ($this->router->getRoutes() as $route) {
            foreach ($this->methods($route) as $method) {
                $posture = $this->postureFor($route, $method);
                $postures[SurfaceSignature::normalize($posture->signature)] = $posture;
            }
        }

        ksort($postures);

        return $postures;
    }

    /**
     * The negative-space direction, **read from the audit that already owns it**. Each row names a live
     * surface that declares no data shape, already tiered and already exempted.
     *
     * @return list<array{uri: string, methods: list<string>, name: string|null, action: string, tier: string, location: string}>
     */
    public function undeclared(): array
    {
        return $this->undeclaredSurface->undeclared();
    }

    private function postureFor(RouteInstance $route, string $method): RoutePostureData
    {
        $middleware = $this->resolvedMiddleware($route);

        $resourceKey = $route->defaults[ParticleController::RESOURCE] ?? null;
        $operationName = $route->defaults[ParticleOperationController::NAME] ?? null;
        $operationResource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;

        $ability = null;
        $onPipeline = false;

        if (is_string($operationResource) && is_string($operationName)) {
            $onPipeline = true;
            $ability = $this->operations->find($operationResource, $operationName)?->ability;
        } elseif (is_string($resourceKey) && $this->resources->has($resourceKey)) {
            $onPipeline = true;
            $ability = $this->resources->get($resourceKey)->policy;
        }

        return new RoutePostureData(
            signature: strtoupper($method).' /'.ltrim($route->uri(), '/'),
            path: '/'.ltrim($route->uri(), '/'),
            method: strtoupper($method),
            name: $route->getName(),
            facets: $this->facets($middleware, $onPipeline, $ability),
            resourceKey: is_string($resourceKey) ? $resourceKey : (is_string($operationResource) ? $operationResource : null),
            operationName: is_string($operationName) ? $operationName : null,
            ability: $ability,
            middleware: $middleware,
        );
    }

    /**
     * @param  list<string>  $middleware
     * @return array<string, bool>
     */
    private function facets(array $middleware, bool $onPipeline, ?string $ability): array
    {
        // Determinable in both directions: the resolved stack is the whole truth about middleware.
        $facets = [
            PostureFacet::AuthRequired->value => $this->matches($middleware, PostureFacet::AuthRequired, self::DEFAULT_AUTH_MIDDLEWARE),
            PostureFacet::RateLimited->value => $this->matches($middleware, PostureFacet::RateLimited, self::DEFAULT_RATE_LIMIT_MIDDLEWARE),
        ];

        // Authorization: a `can:` middleware proves it; on the particle pipeline the registry answers
        // authoritatively either way (a declared `ability:` or the deliberate absence of one). Off the
        // pipeline with no `can:`, a gate may live somewhere no static walk reaches — omit.
        $canMiddleware = $this->matches($middleware, PostureFacet::AuthorizationPolicy, self::DEFAULT_AUTHORIZATION_MIDDLEWARE);

        if ($canMiddleware) {
            $facets[PostureFacet::AuthorizationPolicy->value] = true;
        } elseif ($onPipeline) {
            $facets[PostureFacet::AuthorizationPolicy->value] = $ability !== null && $ability !== '';
        }

        // Positively determinable only — see the class docblock. Absence is not evidence here.
        if ($this->matches($middleware, PostureFacet::TenantScoped, self::DEFAULT_TENANCY_MIDDLEWARE)) {
            $facets[PostureFacet::TenantScoped->value] = true;
        }

        if ($this->matches($middleware, PostureFacet::AuditLogged, self::DEFAULT_AUDIT_MIDDLEWARE)) {
            $facets[PostureFacet::AuditLogged->value] = true;
        }

        return $facets;
    }

    /**
     * @param  list<string>  $middleware
     * @param  list<string>  $defaults
     */
    private function matches(array $middleware, PostureFacet $facet, array $defaults): bool
    {
        $signals = $this->middlewareSignals[$facet->value] ?? $defaults;

        foreach ($middleware as $entry) {
            // A parameterized entry arrives as `auth:sanctum` / `throttle:api`; compare on the name only.
            $name = ltrim(explode(':', $entry, 2)[0], '\\');

            foreach ($signals as $signal) {
                $signal = ltrim($signal, '\\');

                if ($name === $signal || str_starts_with($name, $signal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The route's middleware with groups and aliases expanded. `gatherRouteMiddleware` is the resolved
     * form — the route's own `gatherMiddleware()` returns group NAMES (`api`), which would make every
     * facet answer "no" for a route whose auth comes from its group.
     *
     * @return list<string>
     */
    private function resolvedMiddleware(RouteInstance $route): array
    {
        $resolved = array_map(
            fn ($entry) => is_string($entry) ? $entry : (is_object($entry) ? $entry::class : (string) json_encode($entry)),
            $this->router->gatherRouteMiddleware($route),
        );

        return array_values(array_unique($resolved));
    }

    /** @return list<string> */
    private function methods(RouteInstance $route): array
    {
        return array_values(array_filter($route->methods(), fn (string $method) => $method !== 'HEAD'));
    }
}
