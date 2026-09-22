<?php

namespace Splicewire\Beam\Filters;

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Reflection\FilterReflector;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Resolves the filter declaration used by metadata, query execution and saved views. */
class ResourceFilterDefinition
{
    public function __construct(private ParticleResourceRegistry $particles) {}

    public function definition(string $key): ?ResourceDefinition
    {
        $particle = $this->particles->find($key);
        $frame = $particle === null && app()->bound(ResourceRegistry::class)
            ? app(ResourceRegistry::class)->find($key)
            : null;
        $backings = new BackingResolver;
        $ownsVocabulary = $particle !== null && $backings->hasCapability($particle->backing, DeclaresFilterVocabulary::class);
        if ($ownsVocabulary
            && DataFilter::registry()->has($key)) {
            throw new \LogicException("Resource [{$key}] declares both backing-owned filters and a data-filters query.");
        }

        // A broken explicit registration is an error, never an absent capability.
        $registered = DataFilter::tryResource($key);
        if ($registered !== null) {
            if ($particle !== null && ! $backings->hasCapability($particle->backing, QueriesRecords::class)) {
                throw new \LogicException("Resource [{$key}] registers a filter query but its backing cannot compose queries.");
            }
            $model = $particle?->modelClass() ?? $frame?->model;
            if ($model !== null && $registered->model !== $model) {
                throw new \LogicException("Filter query for [{$key}] names a different model from its resource backing.");
            }
            $this->assertQuery($registered);

            return $registered;
        }

        $data = $particle?->data ?? $frame?->data;
        $query = $particle?->query ?? $frame?->query;
        $queryable = $particle === null || $backings->hasCapability($particle->backing, QueriesRecords::class);
        if ($query !== null && ($ownsVocabulary || ! $queryable || $data === null)) {
            throw new \LogicException("Resource [{$key}] declares a query without an exclusive queryable Data definition.");
        }
        if ($ownsVocabulary || ! $queryable || $data === null) {
            return null;
        }

        $reflector = new FilterReflector;
        if ($query === null && $reflector->filterNames($data) === []
            && $reflector->sortNames($data) === [] && $reflector->includeNames($data) === []) {
            return null;
        }

        $model = $particle?->modelClass() ?? $frame?->model;
        if ($model === null) {
            throw new \LogicException("Resource [{$key}] declares query filters without a model-backed query.");
        }

        $definition = new ResourceDefinition(
            key: $key, data: $data, query: $query ?? DeclaredResourceQuery::class,
        );
        // Publish into the existing filter registry so options, variants and saved-view validation
        // consume the same inferred declaration. Resolve the backing model per request; never cache it.
        $this->assertQuery($definition);
        DataFilter::registry()->registerDefinition($definition);

        return $definition->withModel($model);
    }

    public function hasVocabulary(ParticleResource $resource): bool
    {
        $definition = $this->definition($resource->key);
        if ((new BackingResolver)->hasCapability($resource->backing, DeclaresFilterVocabulary::class)) {
            return ! $resource->backing()->filterVocabulary()->isEmpty();
        }
        if ($definition === null) {
            return false;
        }

        $query = $this->query($definition);

        return $query->filterNames() !== [] || $query->sortNames() !== [] || $query->includeNames() !== [];
    }

    private function assertQuery(ResourceDefinition $definition): void
    {
        if (! is_a($definition->query, ResourceQuery::class, true)) {
            throw new \LogicException("Filter query [{$definition->query}] for [{$definition->key}] must extend ".ResourceQuery::class.'.');
        }
    }

    public function query(ResourceDefinition $definition): ResourceQuery
    {
        return app()->make($definition->query, ['definition' => $definition]);
    }
}
