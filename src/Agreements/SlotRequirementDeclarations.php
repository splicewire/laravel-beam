<?php

namespace Splicewire\Beam\Agreements;

use Countable;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Splicewire\Beam\Agreements\Http\RequireAgreement;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use WeakMap;

/**
 * Beam's default {@see RequirementDeclarations} (agreements 05): the declarations' own `requires:` slots.
 *
 * An operation's Requirements are its behavior's ({@see ParticleOperation::key()}). A resource's go to
 * {@see ParticleResource::readBehavior()} (`on: read`), {@see ParticleResource::writeBehavior()} (`on: write`) or both.
 * It reads the BOOTED registries on every call, unfiltered, so an entry registered after boot is seen and a gated
 * registry cannot hide a Requirement.
 *
 * The ROUTE half (agreements 07: a named route's own `agreement:` middleware) is read once per route collection and
 * count, not once per call ({@see routeDeclarations()}; credential-scope 18). Frame's manifest asks
 * {@see requirementsFor()} twice per resource projection, about 3700 times on a flagship Root manifest, and walking
 * all 1432 routes on each call made the manifest about 4x slower. The registries are still read on every call.
 *
 * With no slot anywhere it declares nothing, exactly as {@see NullRequirementDeclarations}. An agreements seam that
 * binds its own declarations resolves this class to read the slots, so it stays public and resolvable on its own.
 *
 * @api
 */
class SlotRequirementDeclarations implements RequirementDeclarations
{
    /**
     * The route-derived declarations per route collection: `[count, declared]`. Static, because the port is bound
     * non-shared (`bindIf`, and a seam's plain `bind`), so every `app(RequirementDeclarations::class)` is a new
     * instance. A WeakMap keyed on the collection object, so a new application (each test, an Octane worker's fresh
     * router) never reads another's entry and a dropped collection frees its entry.
     *
     * @var WeakMap<RouteCollectionInterface, array{0: int, 1: array<string, list<Requires>>}>|null
     */
    private static ?WeakMap $routeMemo = null;

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected ParticleResourceRegistry $resources,
    ) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $this->all()[$behavior] ?? [];
    }

    public function all(): array
    {
        $declared = [];

        foreach ($this->operations->unfiltered()->matches('beam.particle.operations') as $operation) {
            if ($operation instanceof ParticleOperation && $operation->requires !== null && $operation->requires !== []) {
                $declared[$operation->key()] = array_values($operation->requires);
            }
        }

        foreach ($this->resources->unfiltered()->all() as $resource) {
            if (! $resource instanceof ParticleResource || $resource->requires === null) {
                continue;
            }

            foreach ($resource->requires as $requirement) {
                if ($requirement->bindsRead()) {
                    $declared[$resource->readBehavior()][] = $requirement;
                }
                if ($requirement->bindsWrite()) {
                    $declared[$resource->writeBehavior()][] = $requirement;
                }
            }
        }

        // agreements 07: a named route's own `agreement:` middleware declares for `route:{name}` (ADR-0247 amendment 07).
        foreach ($this->routeDeclarations() as $behavior => $requirements) {
            foreach ($requirements as $requirement) {
                $declared[$behavior][] = $requirement;
            }
        }

        return $declared;
    }

    /**
     * Each named route's `agreement:` declarations, keyed `route:{name}`, walked once per route collection and count.
     * A route registered after boot changes the count, so the next call walks again and sees it. What the count does
     * not see is an edit in place after a read: a route REPLACED at the same method and URI, or a route's middleware
     * or name changed after the collection was last read. Routes are declared before a request reads requirements,
     * so neither happens in a booted app. A collection that is not Countable is walked on every call, as before.
     *
     * @return array<string, list<Requires>>
     */
    protected function routeDeclarations(): array
    {
        if (! app()->bound('router')) {
            return [];
        }

        $collection = app('router')->getRoutes();
        $count = $collection instanceof Countable ? count($collection) : null;
        self::$routeMemo ??= new WeakMap;

        if ($count !== null && isset(self::$routeMemo[$collection]) && self::$routeMemo[$collection][0] === $count) {
            return self::$routeMemo[$collection][1];
        }

        $declared = [];

        foreach ($this->routes($collection) as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            foreach (RequireAgreement::declaredOn($route) as $declaration) {
                $declared[RouteRequirementGate::BEHAVIOR_PREFIX.$name][] = $declaration['requires'];
            }
        }

        if ($count !== null) {
            self::$routeMemo[$collection] = [$count, $declared];
        }

        return $declared;
    }

    /**
     * The one route walk ({@see routeDeclarations()}). A seam for the guard test that counts walks per request.
     *
     * @return iterable<Route>
     */
    protected function routes(RouteCollectionInterface $collection): iterable
    {
        return $collection->getRoutes();
    }
}
