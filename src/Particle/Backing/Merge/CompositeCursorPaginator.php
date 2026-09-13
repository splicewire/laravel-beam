<?php

namespace Splicewire\Beam\Particle\Backing\Merge;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * The composite's page: exactly the rows {@see OrderedMergeStrategy} decided belong on it, plus a
 * PRECOMPUTED next cursor — never one Laravel derives per-item.
 *
 * ## Why not the stock `CursorPaginator`'s own next-cursor mechanics
 *
 * `AbstractCursorPaginator::getParametersForItem()` reads named attributes off the LAST item to build
 * the next `Cursor` — the right mechanic when every row is the same shape and the cursor is "the last
 * row's own sort column". A composite's cursor is a MAP of per-arm cursors, one per source, and no
 * single merged row carries that map (a review-queue row is a `circuit` OR a `composition` item, never
 * both). So the composite computes its own next cursor from arm-level bookkeeping the merge already did
 * — which arm each taken row came from, and that arm's own resumption point — and this class simply
 * carries the precomputed answer rather than re-deriving a wrong one from `$items->last()`.
 *
 * Backward paging (`previousCursor()`) is out of scope for ticket 03 (the PRD tests forward round-trip
 * only) and deliberately answers null: a `null` previous cursor is "no earlier page", which is honest
 * for a paginator that never computed one, rather than a stock mechanism producing a cursor nothing
 * downstream of it can interpret.
 */
final class CompositeCursorPaginator extends CursorPaginator
{
    /**
     * @param  iterable<int, mixed>  $items  already trimmed to at most `$perPage` rows
     */
    public function __construct(
        iterable $items,
        int $perPage,
        ?Cursor $cursor,
        private readonly ?Cursor $forwardCursor,
        private readonly bool $more,
    ) {
        parent::__construct($items, $perPage, $cursor, []);
    }

    public function hasMorePages(): bool
    {
        return $this->more;
    }

    public function nextCursor(): ?Cursor
    {
        return $this->more ? $this->forwardCursor : null;
    }

    public function previousCursor(): ?Cursor
    {
        return null;
    }
}
