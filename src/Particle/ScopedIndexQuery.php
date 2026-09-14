<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;

/**
 * The ONE scoped list query behind a Frame resource — what {@see ParticleFrameResourceHandler::index()}
 * reads and what {@see BeamResourceSummaryProvider} counts.
 *
 * Extracted from the handler (realm-dashboards ticket 02) so a summary figure and the index it summarizes
 * are provably one read. A count taken off `QueriesRecords::query([])` directly would be the UNSCOPED
 * builder: no owner scope, no `filter[...]`, no declared `scope` closure, no realm — a tenant-realm tile
 * showing the global total. Every caller that wants "the rows this actor's index would list" comes here.
 *
 * Two paths, mirroring both transports (REST {@see ParticleController::index}
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
     * saved-filter-capable query the REST {@see ParticleController::index}
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

    /** The registered particle declaration for this resource key, if any (null for a manifest-only resource). */
    protected function resource(ResourceDefinition $definition): ?ParticleResource
    {
        return $this->registry->has($definition->key) ? $this->registry->get($definition->key) : null;
    }
}
