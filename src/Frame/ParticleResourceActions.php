<?php

namespace Splicewire\Beam\Frame;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Schemastud\Frame\Registry\ResourceActionDefinition;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\ParentSubject;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;

/**
 * Projects a resource's opted-in `#[ParticleOp]`s onto frame's generic ACTION concept (ADR-0223, frame
 * ADR-0005) — the list `ParticleResource::toResourceDefinition()` hands frame as `actions:`.
 *
 * An operation is projected when all three hold, and silently left out otherwise:
 *
 *  1. it declares an `affordance:` ({@see ParticleOperation::actionAffordance()}) — `false` and undeclared
 *     both project nothing, and the audit counts the second;
 *  2. its subject has a placement frame can draw: NO coordinates (`NoSubject`, `ActorSubject`) is a
 *     `resource` action beside the list's "New"; exactly `{id}` (`RecordSubject`, `ColumnSubject`) is a
 *     `record` action on each row and on the detail page. A `ParentSubject` op addresses a record through
 *     an edge frame has no concept of, and is out of scope (ticket 21, decision 2);
 *  3. it is MOUNTED in this host — the action's URL is the op's own route, read off the router by the
 *     resource/name defaults `ParticleMounter::op()` stamps. An op a host registered and never mounted has
 *     no URL to press, so it has no button. A mount under a parent parameter the button cannot fill is
 *     skipped the same way.
 *
 * A mount parameter the CURRENT request already binds is not the client's to fill (frame-foundation 75): under
 * tower's path or host topology every tenant route carries `{tenant}`, and the tenant manifest is asked for
 * under that same tenant. Such a parameter is filled from the matched route, so the client never sees it.
 * `{id}` is never filled this way.
 *
 * The URL is RELATIVE to the host (`/{route uri}`) and a record URL keeps its `{id}` placeholder, which the
 * client fills per row. The op's mount stays the enforcement; this only says where it is.
 *
 * The route index is built lazily and kept, because the manifest asks for every framed resource and a
 * host's router holds hundreds of routes. It is rebuilt when the route table has grown since, since a
 * package may add routes after boot (commerce's seat mounts its API in a `booted()` callback), and the
 * instance is scoped per request (`BeamServiceProvider`).
 */
class ParticleResourceActions
{
    /** @var array<string, array<string, Route>>|null `resource.name` ⇒ realm (empty string is neutral) ⇒ first route */
    protected ?array $routes = null;

    protected int $indexedRouteCount = 0;

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected Router $router,
    ) {}

    /**
     * @return list<ResourceActionDefinition>
     */
    public function for(string $resourceKey, ?string $realm = null): array
    {
        $actions = [];

        foreach ($this->operations->forResource($resourceKey) as $operation) {
            $action = $this->project($operation, $realm);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public function project(ParticleOperation $operation, ?string $realm = null): ?ResourceActionDefinition
    {
        $affordance = $operation->actionAffordance();

        if ($affordance === null) {
            return null;
        }

        $scope = $this->scope($operation);
        $route = $this->route($operation, $realm);

        if ($scope === null || $route === null) {
            return null;
        }

        $bound = $this->boundParameters($route);

        if (! $this->fillable($route, $scope, $bound)) {
            return null;
        }

        return new ResourceActionDefinition(
            key: $operation->name,
            label: $affordance->label ?? Str::headline($operation->name),
            scope: $scope,
            method: strtoupper($operation->method?->value ?? 'post'),
            url: '/'.ltrim($this->substitute($route->uri(), $bound), '/'),
            input: is_string($operation->input) ? $operation->input : null,
            result: $affordance->result,
            destructive: $affordance->destructive,
            // frame-foundation 83: the agreement keys this action's op requires (its dotted behaviour key), keys only;
            // Optional (no `requires` key) when it requires none.
            requires: $this->requires($operation),
        );
    }

    /** @return list<string>|Optional */
    private function requires(ParticleOperation $operation): array|Optional
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn (Requires $requires): ?string => $requires->agreement,
            app(RequirementDeclarations::class)->requirementsFor($operation->key()),
        ))));

        return $keys === [] ? new Optional : $keys;
    }

    /** The ability enforced by the matched mount, including its narrow tenant author mapping. */
    public function effectiveAbility(
        ParticleOperation $operation,
        ?string $realm = null,
        ?array $routeOverrides = null,
    ): string|false|null {
        $ability = $operation->ability;

        if (! is_string($ability) || $ability !== 'ux.author' || $realm !== 'tenant') {
            return $ability;
        }

        $overrides = $routeOverrides
            ?? ($this->route($operation, $realm)?->defaults[ParticleOperationController::ABILITY_OVERRIDES] ?? []);

        return is_array($overrides) && is_string($overrides[$ability] ?? null)
            ? $overrides[$ability]
            : $ability;
    }

    /** Apply the mounted tenant realm's declared map to a resource read policy. */
    public function effectiveResourceReadAbility(
        string $resourceKey,
        string $ability,
        ?string $realm = null,
        ?array $routeOverrides = null,
    ): string {
        if ($realm !== 'tenant') {
            return $ability;
        }

        $overrides = $routeOverrides ?? $this->overridesForResource($resourceKey, $realm);

        return $ability === 'ux.author' && is_string($overrides['ux.author'] ?? null)
            ? $overrides['ux.author']
            : $ability;
    }

    /**
     * Where the op renders, from its subject's coordinates; null when frame has no placement for it.
     *
     * @return 'resource'|'record'|null
     */
    public function scope(ParticleOperation $operation): ?string
    {
        $subject = $operation->subject;

        if ($subject instanceof ParentSubject || (is_string($subject) && is_a($subject, ParentSubject::class, true))) {
            return null;
        }

        return match (SubjectResolvers::coordinates($operation)) {
            [] => ResourceActionDefinition::ScopeResource,
            ['id'] => ResourceActionDefinition::ScopeRecord,
            default => null,
        };
    }

    /**
     * Whether the client can fill every parameter of this mount that the current request does not already bind:
     * none for a resource action, `{id}` for a record one.
     *
     * @param  array<string, string>  $bound
     */
    protected function fillable(Route $route, string $scope, array $bound = []): bool
    {
        $parameters = array_values(array_diff($route->parameterNames(), array_keys($bound)));

        return $scope === ResourceActionDefinition::ScopeRecord
            ? $parameters === ['id']
            : $parameters === [];
    }

    /**
     * The mount's parameters the matched request route already binds, as URL segments. `id` is never taken: it
     * is the record's, which the client fills per row. An absent or empty segment stays unbound, so the mount is
     * still dropped rather than given a guessed one.
     *
     * @return array<string, string>
     */
    protected function boundParameters(Route $route): array
    {
        $current = $this->router->current();

        // The request's ORIGINAL parameters, as matched off its URL: tenant identification by path (stancl's
        // `PathTenantResolver`, run by tower before every tenant surface) FORGETS `{tenant}` from `parameters()`
        // once it has initialized tenancy, and a model-bound parameter would hand back its primary key where
        // the URL carried a slug. The matched segment is what the action's own mount will match again.
        $original = $current !== null && $current->hasParameters() ? $current->originalParameters() : [];

        $bound = [];

        foreach ($route->parameterNames() as $name) {
            $value = $original[$name] ?? null;

            if ($name !== 'id' && ((is_string($value) && $value !== '') || is_int($value))) {
                $bound[$name] = (string) $value;
            }
        }

        return $bound;
    }

    /** @param  array<string, string>  $bound */
    protected function substitute(string $uri, array $bound): string
    {
        foreach ($bound as $name => $segment) {
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], rawurlencode($segment), $uri);
        }

        return $uri;
    }

    protected function route(ParticleOperation $operation, ?string $realm): ?Route
    {
        $all = $this->router->getRoutes()->getRoutes();

        // Rebuilt when the route table has grown since the last build: a definition asked for during boot
        // (before a package's `booted()` mount, or before the host's route files load) must not freeze an
        // index with no operation routes in it for the rest of the process.
        if ($this->routes === null || count($all) !== $this->indexedRouteCount) {
            $this->routes = [];
            $this->indexedRouteCount = count($all);

            foreach ($all as $route) {
                $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
                $name = $route->defaults[ParticleOperationController::NAME] ?? null;

                if (is_string($resource) && is_string($name)) {
                    $mountedRealm = $route->defaults[ParticleOperationController::REALM] ?? null;
                    $realmKey = is_string($mountedRealm) ? $mountedRealm : '';
                    $this->routes["{$resource}.{$name}"][$realmKey] ??= $route;
                }
            }
        }

        $routes = $this->routes[$operation->key()] ?? [];

        if ($realm !== null && isset($routes[$realm])) {
            return $routes[$realm];
        }

        return $routes[''] ?? null;
    }

    /** @return array<string, string> */
    protected function overridesForResource(string $resourceKey, string $realm): array
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (($route->defaults[ParticleOperationController::RESOURCE] ?? null) !== $resourceKey
                || ($route->defaults[ParticleOperationController::REALM] ?? null) !== $realm) {
                continue;
            }

            $overrides = $route->defaults[ParticleOperationController::ABILITY_OVERRIDES] ?? null;

            if (is_array($overrides)) {
                return $overrides;
            }
        }

        return [];
    }
}
