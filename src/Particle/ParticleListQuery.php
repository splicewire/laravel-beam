<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Rushing\DataFilters\Reflection\FilterReflector;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;

/**
 * The base query for a NON-filterable index — the ONE builder both transports call.
 *
 * Two transports serve the same declaration's list: REST ({@see ParticleController::index})
 * and Frame ({@see ParticleFrameResourceHandler::index}). Each had its own base query, and each
 * implemented exactly the half the other was missing (particle-contribution-seam ticket 05, measured
 * 2026-08-23):
 *
 *   - REST's `defaultSortedQuery()` honoured the declared `#[Sortable(default: true)]` order and NEVER
 *     eager-loaded `$resource->includes` — the declaration's include list was silently dropped on every
 *     non-filterable list read. Where the read Data class projects the relation that is a per-row lazy
 *     load (an N+1); where it does not, the include was simply inert. The
 *     `$query->with($resource->includes)` the map recorded as "the list path already batches" is the
 *     SUBJECT-RESOLUTION query for show/update/destroy, not the index.
 *   - Frame's `indexQuery()` eager-loaded the includes and then hardcoded `orderByDesc('created_at')`,
 *     ignoring the sortable attribute that `defaultSortedQuery()`'s own docblock calls "the SINGLE source
 *     of truth for a resource's default order".
 *
 * Neither was a local bug: they are one absent shared builder, and keeping two hand-synced base queries
 * is the same obligation the host double-DECLARATION habit documents in its own comment. So the includes
 * axis and the sort axis compose HERE, once, and both transports call it.
 *
 * DELIBERATELY NOT here: the `scope` closure. Both transports apply it, but they apply it differently —
 * Frame passes the resolved editor realm as its second argument, REST passes none — and folding that in
 * would start handing REST a realm it has never received. `scope` is row-level AUTHORIZATION (ADR-0156
 * §83); ticket 05 §Q7 ruled a change to who may narrow a list needs its own argument, not a ride on a
 * perf fix. Each transport keeps applying it exactly where it does today.
 *
 * Expected lifespan: this is the `query()` half of the per-resource `ResourceBacking` port
 * ({@see ParticleResource} → particle-contribution-seam ticket 11's
 * `QueriesRecords` capability). Ticket 13 absorbs it; it is a shared builder now so that the conversion
 * has one call site to move rather than two to reconcile.
 */
class ParticleListQuery
{
    /**
     * The declaration's list base: its backing's query, its declared `includes` eager-loaded, ordered
     * by its declared default sort.
     *
     * ⚠️ Requires a backing that {@see QueriesRecords} — this method composes a `Builder`, which is the
     * Eloquent-only capability. A resource whose backing only {@see StreamsRecords} owns its own paging
     * and never reaches here.
     *
     * The default order is read from the read Data class's `#[Sortable(default: true)]` property via
     * {@see FilterReflector::defaultSortColumn()}, which returns the declared COLUMN and direction —
     * NOT the deprecated `defaultSort()`, whose string is the sort KEY and drops a
     * `#[Sortable(name:, column:)]` mapping whenever the two diverge, leaving the index ordering by a
     * column that does not exist. Absent any opt-in we fall back to the framework `latest()` (newest
     * `created_at` first — the historical default when no column was declared, and what Frame hardcoded).
     */
    public function forList(ParticleResource $resource, array $filters = []): Builder
    {
        $backing = $resource->backing();

        if (! $backing instanceof QueriesRecords) {
            throw new RuntimeException(
                "Resource [{$resource->key}] cannot build a list query: its backing [".$backing::class
                .'] does not implement '.QueriesRecords::class.'.'
            );
        }

        $query = $backing->query($filters);

        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }

        return $this->ordered($query, $resource);
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
