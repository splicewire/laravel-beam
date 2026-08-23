<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Expresses {@see StreamsRecords::stream()} in terms of {@see QueriesRecords::query()}, so a backing
 * with a real Eloquent query gets the GENERAL record-yielding capability for free.
 *
 * This is the reason the two capabilities can stay honestly separate. `StreamsRecords` is what an
 * external backing can implement and what a caller may demand of ANY backing; `QueriesRecords` returns
 * a composable `Builder` and is Eloquent-only. Without this trait every Eloquent backing would have to
 * hand-roll paging to satisfy the general capability, and the two would collapse back into one
 * discriminated contract — the thing polymorphism replaced.
 *
 * A backing that needs different paging (a keyset over a non-id column, a joined cursor) implements
 * `stream()` itself and does not use this trait.
 *
 * @see EloquentBacking  the model-backed default that uses it
 */
trait BacksEloquent
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return $this->query($filters)->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }
}
