<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;
use Rushing\DataFilters\Reflection\FilterReflector;
use Splicewire\Beam\Filters\FilterQuerySelection;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;

/** Composes one declaration's backing, row boundaries and query controls for every list transport. */
class ParticleListQuery
{
    public function forList(ParticleResource $resource, array $filters = [], ?Request $request = null, ?Builder $relative = null): Builder
    {
        $request ??= request();
        $query = $this->backingQuery($resource, $filters);
        $query = $this->scoped($query, $resource, $request);
        if ($relative !== null) {
            $this->intersect($query, $relative);
        }

        $selection = app(FilterQuerySelection::class);
        $query = $selection->applyTo($resource->key, $query, $request);
        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }
        $this->withContributedIncludes($query, $resource, $filters);

        return empty($query->getQuery()->orders) ? $this->ordered($query, $resource) : $query;
    }

    /** Bases examined before user controls or mechanical intersections can look like authorization. */
    public function authorizationBases(ParticleResource $resource, Request $request): array
    {
        $request = FilterQuerySelection::scopeRequest($request);
        $bases = [$this->scoped($this->backingQuery($resource, []), $resource, $request)];
        $resolver = app(ResourceFilterDefinition::class);
        $definition = $resolver->definition($resource->key);
        if ($definition !== null) {
            $bases[] = $resolver->query($definition)->authorizationQuery($request);
        }

        return $bases;
    }

    public function scoped(Builder $query, ParticleResource $resource, Request $request): Builder
    {
        $realm = $request->route()?->defaults['realm'] ?? null;

        return $resource->scope !== null ? (($resource->scope)($query, $realm) ?? $query) : $query;
    }

    public function intersect(Builder $query, Builder $scope): Builder
    {
        $key = $query->getModel()->getQualifiedKeyName();
        $ids = (clone $scope)->select($scope->getModel()->getQualifiedKeyName())->reorder()
            ->toBase()->cloneWithout(['limit', 'offset', 'unionLimit', 'unionOffset']);

        return $query->whereIn($key, $ids);
    }

    private function backingQuery(ParticleResource $resource, array $filters): Builder
    {
        $backing = $resource->backing();
        if (! $backing instanceof QueriesRecords) {
            throw new RuntimeException("Resource [{$resource->key}] backing does not implement ".QueriesRecords::class.'.');
        }

        return $backing->query($filters);
    }

    /**
     * Eager-load the REQUEST-PARAMETERIZED contributed includes — the one job a static `includes:` list
     * provably cannot do.
     *
     * `billStatus`/`billTotal` need `with(['bills' => fn ($q) => $q->forPeriod($period)])`, where
     * `$period` comes off the request's `filter[period]` facet. A `list<string>` cannot express a
     * constrained relation, so a contribution may declare its includes arm as a Closure taking the facet
     * bag instead ({@see ResourceContribution::$includes}).
     *
     * ⚠️ It resolves HERE, once per request, and this is the only call site on purpose. The static arm
     * already folded in {@see ParticleResourceRegistry::get()} — which both transports share, and which
     * therefore covers the detail and subject-resolution paths this list builder never sees. Resolving
     * the dynamic arm per record instead would reproduce the very N+1 the includes arm exists to remove
     * (ticket 05 §A4).
     *
     * Inert when nothing is bound or nothing contributes: the projector is resolved off the container so
     * a bare test that news up this builder keeps working with no contribution registry at all.
     *
     * @param  array<string, mixed>  $filters  the opaque facet bag
     */
    protected function withContributedIncludes(Builder $query, ParticleResource $resource, array $filters): void
    {
        if (! app()->bound(ResourceContributionRegistry::class)) {
            return;
        }

        $contributed = (new ContributionProjector(app(ResourceContributionRegistry::class)))
            ->dynamicIncludes($resource->key, $filters);

        if ($contributed !== []) {
            $query->with($contributed);
        }
    }

    /**
     * Apply the declaration's default order to an already-built query.
     *
     * A resource may legitimately declare NO output DTO (its wire type is a package-owned class, not an
     * App Data DTO — e.g. `runner_transform`), and `defaultSortColumn()` takes a non-nullable
     * class-string, so a null `data:` skips straight to the framework default rather than TypeError.
     */
    protected function ordered(Builder $query, ParticleResource $resource): Builder
    {
        $default = $resource->data !== null
            ? (new FilterReflector)->defaultSortColumn($resource->data)
            : null;

        if ($default === null) {
            return $query->latest();
        }

        return $default['direction'] === 'desc'
            ? $query->orderByDesc($default['column'])
            : $query->orderBy($default['column']);
    }
}
