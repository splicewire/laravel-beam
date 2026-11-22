<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Splicewire\Beam\Agreements\Http\RequireAgreement;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Beam's default {@see RequirementDeclarations} (agreements 05): the declarations' own `requires:` slots.
 *
 * An operation's Requirements are its behavior's ({@see ParticleOperation::key()}). A resource's go to
 * {@see ParticleResource::readBehavior()} (`on: read`), {@see ParticleResource::writeBehavior()} (`on: write`) or both.
 * It reads the BOOTED registries on every call, unfiltered, so an entry registered after boot is seen and a gated
 * registry cannot hide a Requirement.
 *
 * The ROUTE half (agreements 07: a named route's own `agreement:` middleware) is walked at most once per request, queue
 * job or console command (a nested one keeps its parent's memo), through {@see RouteRequirementIndex} (credential-scope
 * 18), and on every call outside one. Frame's manifest asks {@see requirementsFor()} twice per resource projection, and
 * a walk on each call made the flagship's Root manifest about 4x slower. The registries are still read on every call.
 *
 * With no slot anywhere it declares nothing, exactly as {@see NullRequirementDeclarations}. An agreements seam that
 * binds its own declarations resolves this class to read the slots, so it stays public and resolvable on its own.
 *
 * @api
 */
class SlotRequirementDeclarations implements RequirementDeclarations
{
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
     * Each named route's `agreement:` declarations, keyed `route:{name}`: memoised in the open request's, job's or
     * command's frame ({@see RouteRequirementIndex}), walked on every call outside one.
     *
     * @return array<string, list<Requires>>
     */
    protected function routeDeclarations(): array
    {
        return RouteRequirementIndex::read(fn (): array => $this->walkRoutes());
    }

    /**
     * One walk of the router's routes.
     *
     * @return array<string, list<Requires>>
     */
    protected function walkRoutes(): array
    {
        if (! app()->bound('router')) {
            return [];
        }

        $declared = [];

        foreach ($this->routes(app('router')->getRoutes()) as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            foreach (RequireAgreement::declaredOn($route) as $declaration) {
                $declared[RouteRequirementGate::BEHAVIOR_PREFIX.$name][] = $declaration['requires'];
            }
        }

        return $declared;
    }

    /**
     * The routes {@see walkRoutes()} reads.
     *
     * @return iterable<Route>
     */
    protected function routes(RouteCollectionInterface $collection): iterable
    {
        return $collection->getRoutes();
    }
}
