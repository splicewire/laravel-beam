<?php

namespace Splicewire\Beam\Frame;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Schemastud\Frame\Registry\ResourceActionDefinition;
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
    /** @var array<string, Route>|null `resource.name` ⇒ the first route mounting it */
    protected ?array $routes = null;

    protected int $indexedRouteCount = 0;

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected Router $router,
    ) {}

    /**
     * @return list<ResourceActionDefinition>
     */
    public function for(string $resourceKey): array
    {
        $actions = [];

        foreach ($this->operations->forResource($resourceKey) as $operation) {
            $action = $this->project($operation);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public function project(ParticleOperation $operation): ?ResourceActionDefinition
    {
        $affordance = $operation->actionAffordance();

        if ($affordance === null) {
            return null;
        }

        $scope = $this->scope($operation);
        $route = $this->route($operation);

        if ($scope === null || $route === null || ! $this->fillable($route, $scope)) {
            return null;
        }

        return new ResourceActionDefinition(
            key: $operation->name,
            label: $affordance->label ?? Str::headline($operation->name),
            scope: $scope,
            method: strtoupper($operation->method?->value ?? 'post'),
            url: '/'.ltrim($route->uri(), '/'),
            input: is_string($operation->input) ? $operation->input : null,
            result: $affordance->result,
            destructive: $affordance->destructive,
        );
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

    /** Whether the client can fill every parameter of this mount: none for a resource action, `{id}` for a record one. */
    protected function fillable(Route $route, string $scope): bool
    {
        $parameters = $route->parameterNames();

        return $scope === ResourceActionDefinition::ScopeRecord
            ? $parameters === ['id']
            : $parameters === [];
    }

    protected function route(ParticleOperation $operation): ?Route
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
                    $this->routes["{$resource}.{$name}"] ??= $route;
                }
            }
        }

        return $this->routes[$operation->key()] ?? null;
    }
}
