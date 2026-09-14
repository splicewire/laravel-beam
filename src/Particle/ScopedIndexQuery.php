<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;

/**
 * The ONE scoped list query behind a Frame resource — what {@see ParticleFrameResourceHandler::index()}
 * reads and what `Splicewire\Beam\Summary\BeamResourceSummaryProvider` counts.
 *
 * Extracted from the handler (realm-dashboards ticket 02) so a summary figure and the index it summarizes
 * are provably one read. A count taken off `QueriesRecords::query([])` directly would be the UNSCOPED
 * builder: no owner scope, no `filter[...]`, no declared `scope` closure, no realm — a tenant-realm tile
 * showing the global total. Every caller that wants "the rows this actor's index would list" comes here.
 *
 * Two paths, mirroring both transports (REST `Splicewire\Beam\Http\Particle\ParticleController::index()`
 * applies the same split):
 *  - a registered, `filterable` declaration rides the data-filters builder ({@see ParticleHydrator::query}),
 *    which is its own owner-scoped, `filter[...]`-aware, saved-filter-capable gate;
 *  - anything else rides {@see ParticleListQuery} with the declaration's `scope` closure applied, the
 *    resolved editor realm as its second argument.
 */
class ScopedIndexQuery
{
    public function __construct(
        protected ParticleHydrator $hydrator,
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

    /**
     * The list query for a Frame index. A registered, `filterable` {@see ParticleResource} rides the
     * data-filters builder ({@see ParticleHydrator::query}) — the SAME owner-scoped, `filter[...]`-aware,
     * saved-filter-capable query the REST `Splicewire\Beam\Http\Particle\ParticleController::index()`
     * uses — so a Frame list and a REST list are one read (a pinned `filter[circuitId]` and per-caller
     * row-scoping hold in the editor exactly as they do over REST). A manifest-only resource, a
     * non-filterable one, or a host whose hydrator does not compose queries falls back to the plain
     * includes-eager-loaded query, ordered by the declaration's default sort.
     *
     * That fallback rides {@see ParticleListQuery}, the same builder the REST transport's
     * `defaultSortedQuery` calls. Until ticket 05 the handler hardcoded `orderByDesc('created_at')` here,
     * silently ignoring the `#[Sortable(default: true)]` attribute the REST twin reads and calls "the SINGLE
     * source of truth for a resource's default order" — so the two transports ordered the same
     * declaration's list differently whenever it declared one.
     */
    public function forDefinition(ResourceDefinition $definition): object
    {
        $resource = $this->resource($definition);

        if ($resource !== null && $resource->filterable) {
            try {
                return $this->hydrator->query(
                    $resource->key,
                    ReadContext::list($resource->includes, auth()->user()),
                );
            } catch (\BadMethodCallException) {
                // This hydrator cannot compose a list query for this resource — the degenerate beam-core
                // reader ({@see \Splicewire\Beam\Read\PayloadParticleReader::query()}) never can, and a
                // query-composing one cannot for a key with no filter wiring behind it. Either way: fall
                // through to the plain query.
                //
                // ⚠️ This was `catch (\LogicException)` — a net wide enough to swallow things it was never
                // aimed at, and it did: a data-filters registry miss threw `InvalidArgumentException`,
                // which IS a `LogicException`, so an unwired resource degraded here silently. When
                // `data-filters.resources` conformed to the popcorn kernel that miss became a
                // `RegistryMiss` (a `RuntimeException`) and seven frame reads 500ed at once. Narrowed to
                // the exception the port actually declares, and the real condition is now stated at the
                // hydrator rather than inferred from a base class (registry-kernel ticket 61).
            }
        }

        // A manifest-only resource (no registered ParticleResource) has no declaration to read includes
        // or a default sort off — it keeps the framework default, which is what `created_at desc` was.
        if ($resource === null) {
            return $definition->model::query()->latest();
        }

        // The facet bag rides along for the same reason the REST twin forwards it: a CONTRIBUTED include
        // may be request-parameterized (a constrained eager-load). Frame does not interpret `filter[...]`
        // on a non-filterable resource — it never did — but uninterpreted is not the same as absent, and
        // dropping it here would leave the two transports honouring `filter[period]` differently, which
        // is precisely the drift ticket 05 collapsed these queries to stop.
        $filters = array_filter((array) app(Request::class)->input('filter', []));

        return $this->scoped((new ParticleListQuery)->forList($resource, $filters), $resource);
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
     * The list read's own projection DECLARATION — includes and actor — is the `ReadContext::list()`
     * {@see forDefinition()} hands the hydrator; it shapes the query, not this per-row step, which is why
     * the two live on the same object and why a caller that has one has the other.
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
     * argument. Shared by the handler's subject-resolution base and the non-filterable list base so the
     * two gate identically — ticket 05 split the two queries apart, and an authorization gate applied in
     * only one of them is exactly the drift that split caused elsewhere.
     *
     * Null resource, or a declaration with no `scope`, returns the query untouched.
     */
    public function scoped(Builder $query, ?ParticleResource $resource): Builder
    {
        if ($resource?->scope === null) {
            return $query;
        }

        $realm = app(Request::class)->route()?->defaults['realm'] ?? null;

        return ($resource->scope)($query, $realm) ?? $query;
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
