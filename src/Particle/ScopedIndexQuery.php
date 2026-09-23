<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;

/** Frame indexes and summaries consume the same declaration-derived list composition as REST. */
class ScopedIndexQuery
{
    public function __construct(
        protected ParticleResourceRegistry $registry,
    ) {}

    /**
     * Can a builder be composed for this resource at all? False for a registered declaration whose backing
     * only streams (a composite, a service-backed union), and for a manifest-only resource with no model.
     * A caller that asks this first can decline honestly instead of catching the builder's exception.
     */
    public function queryable(ResourceDefinition $definition): bool
    {
        $resource = $this->resource($definition);

        return $resource === null
            ? $definition->model !== null
            : $resource->backing() instanceof QueriesRecords;
    }

    /** Build the resource's scoped and filtered list. */
    public function forDefinition(ResourceDefinition $definition): object
    {
        $resource = $this->resource($definition);
        $request = app(Request::class);
        if ($resource !== null) {
            ResourceReadGuard::forApp()->inspectRead($resource, $request)->authorize();
        }
        $resource ??= new ParticleResource(
            key: $definition->key, backing: $definition->model, data: $definition->data, query: $definition->query,
        );

        return app(ParticleListQuery::class)->forList($resource, (array) $request->input('filter', []), $request);
    }

    /**
     * The same scoped list query, in the shape an AGGREGATE reads it: {@see forDefinition()} with the
     * declared eager-loads dropped and the declared ordering cleared.
     *
     * Both removals are about the aggregate, not about the scope — the gate is whatever `forDefinition()`
     * applied and is untouched here:
     *  - **includes dropped.** They exist to make the row PROJECTION free; a `count()`/`GROUP BY` projects
     *    no row, so every declared eager-load is a query per figure for nothing.
     *  - **ordering cleared.** An `ORDER BY` a `GROUP BY` does not cover is an ERROR on Postgres, and the
     *    declaration's default sort (`#[Sortable(default: true)]`) is exactly such a column. A provider
     *    that grouped off `forDefinition()` directly worked on sqlite and 500ed at a real host.
     *
     * Every summary provider that counts or groups comes here, so the aggregate shape is spelled once:
     * {@see BeamResourceSummaryProvider} and the tenancy/commerce providers each
     * used to re-spell some subset of it, and only one of them had both halves (realm-dashboards 06a review).
     */
    public function forAggregate(ResourceDefinition $definition): object
    {
        // `setEagerLoads()` / `reorder()` are Eloquent builder methods; a data-filters query builder
        // forwards both to its subject. Either way what comes back is a builder an aggregate can run on.
        return $this->forDefinition($definition)->setEagerLoads([])->reorder();
    }

    /**
     * Project ONE list row to its `Data` — the projection
     * {@see ParticleFrameResourceHandler::streamedIndex()} applies to every row it emits, and therefore
     * the one a provider must apply to show "the rows this resource's index would list".
     *
     * The ladder, in order:
     *  1. the declaration's `project` closure, when it declares one (a Data class whose constructor a
     *     row cannot reach — `CentralActivityData::fromModel`, `CustomerData::fromModel`);
     *  2. the item itself, when a backing already streams `Data` (a composite arm may);
     *  3. `$definition->data::from($item)`, spatie's magic named constructor.
     *
     * ⚠️ NOT the same as `ParticleFrameResourceHandler::projectRead()`: that one additionally folds
     * CONTRIBUTED slices onto a `Model`-backed row and guarantees an `id`. A streamed row has no model to
     * contribute against. Callers wanting the model-backed list row want the handler's, not this.
     */
    public function projectListRow(ResourceDefinition $definition, mixed $item): Data
    {
        $resource = $this->resource($definition);

        if ($resource?->project !== null) {
            return ($resource->project)($item);
        }

        if ($item instanceof Data) {
            return $item;
        }

        /** @var class-string<Data> $dataClass */
        $dataClass = $definition->data;

        return $dataClass::from($item);
    }

    /**
     * Apply the declaration's row-level `scope` closure, with the resolved editor realm as its second
     * argument. Shared by the handler's subject-resolution base and the list base so the
     * two gate identically — ticket 05 split the two queries apart, and an authorization gate applied in
     * only one of them is exactly the drift that split caused elsewhere.
     *
     * Null resource, or a declaration with no `scope`, returns the query untouched.
     */
    public function scoped(Builder $query, ?ParticleResource $resource): Builder
    {
        return $resource === null ? $query : app(ParticleListQuery::class)->scoped($query, $resource, app(Request::class));
    }

    /**
     * The registered particle declaration for this resource key, if any (null for a manifest-only resource).
     *
     * Public because it is the ONE spelling of the lookup: `ParticleFrameResourceHandler::resource()` was a
     * byte-identical copy of this body, over the same registry, and two copies of a registry lookup are two
     * places a miss can start meaning something different.
     */
    public function resource(ResourceDefinition $definition): ?ParticleResource
    {
        return $this->registry->has($definition->key) ? $this->registry->get($definition->key) : null;
    }
}
