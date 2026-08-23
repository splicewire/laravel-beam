<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Capability: this backing can yield a paged stream of records for a list read.
 *
 * ## The general one
 *
 * This is the GENERAL record-yielding capability — the one an external, non-Eloquent backing can
 * honestly implement. It absorbs `Schemastud\Frame\Contracts\UnionSource::index()`, which had exactly
 * this shape (an opaque filter bag in, a `CursorPaginator` out) and exactly this reason: *"the service
 * owns the merge, sort, filter, and pagination — frame does not."*
 *
 * ⚠️ Named `records()`, not `stream()`. Every backing in this estate already has an internal
 * `stream()` — the projected collection it pages over — so a capability method of that name would
 * collide with the implementation it is meant to wrap, on all four of them at once.
 *
 * A backing over one Eloquent model does NOT hand-roll this. {@see EloquentBacking} implements it via
 * {@see BacksEloquent}, which expresses `records()` in terms of {@see QueriesRecords::query()} and the
 * shared `ParticleListQuery` — so the ordinary case gets the general capability for free and keeps the
 * declared includes and the declared default sort.
 *
 * ## `$filters` is the same argument for every backing
 *
 * The bag is the request's whole `filter[...]` surface, passed through opaquely — **the same shape no
 * matter what backs the resource**, which is what makes this capability substitutable at all. Beam does
 * not interpret it; the backing owns its own query semantics. That is already how the estate's four
 * production backings read it (`UnionQuery::$filters`, e.g. `tenants`' `period`).
 *
 * ⚠️ The word is **filters**, matching `UnionQuery::$filters` and the `filter[...]` request surface —
 * not "facets", which the map's prose drifted into.
 *
 * ## Deliberately not more than this
 *
 * Cursor + perPage + filters is what the shipped implementations actually need. Richer capabilities —
 * a declared filter/sort vocabulary a backing can advertise, the external-data cases — get their own
 * interfaces as those specs firm up, rather than being guessed at here.
 */
interface StreamsRecords extends ResourceBacking
{
    /**
     * One page of records for a list read.
     *
     * @param  array<string, mixed>  $filters  the request's opaque `filter[...]` bag; the backing owns
     *                                         its own query semantics and beam never interprets it
     * @param  string|null  $cursor  the encoded cursor, or null for the first page
     * @param  int  $perPage  page size
     */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator;
}
