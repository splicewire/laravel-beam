<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Splicewire\Beam\Particle\Backing\Merge\OrderedMergeStrategy;

/**
 * A backing over rows a service hands back ALREADY MATERIALIZED — the honest shape for a read-model
 * whose narrowing happens in PHP rather than in SQL, and the smallest thing that can be an arm of a
 * {@see CompositeBacking}.
 *
 * ## Why this exists rather than "just use Eloquent"
 *
 * {@see EloquentBacking} pages with a real `LIMIT` and is the right answer whenever the rows are one
 * table and every facet is a column. The estate's union read-models are not that: the review queue's
 * `keywords` facet searches a LABEL that is computed in PHP from three relations, and its workflow arm
 * is a per-principal inversion unioned and deduplicated in memory. Pushing those into a query is a
 * separate piece of work (it means denormalising the label); pretending they are already there would
 * page wrongly, because a `LIMIT 25` filtered down to 3 in PHP is not a page of 25.
 *
 * So this class does the one thing that IS honest about such a source and that the estate was missing:
 * it gives a materialized, ordered collection a **stable keyset cursor**, so page N is the rows after
 * page N−1 and nothing rescans the merged stream looking for the last id. The materialization stays and
 * is named here rather than hidden.
 *
 * ## The cursor
 *
 * `(sortKey, idKey)` — the sort value the composite merges on, plus the row id as the tiebreak, which is
 * exactly the parameter pair `CursorPaginator` reads back off an item through
 * {@see AbstractCursorPaginator::getCursorForItem()}. That is what lets
 * {@see OrderedMergeStrategy} advance this arm without knowing
 * anything about it: it hands the arm's own paginator the last row it took and gets the arm's own
 * resumption point back.
 *
 * Resumption prefers the exact row — the id is looked up in the current collection — and falls back to
 * the first row that ranks strictly below the cursor when that row is gone (approved between two page
 * loads, which on a review queue is the NORMAL case, not an edge one). A cursor naming an id that no
 * longer exists therefore skips forward rather than restarting the arm.
 *
 * ## Ordering is this class's job, not the implementor's
 *
 * `records()` sorts descending by `(sortKey(), idKey())` before it pages, because the merge's correctness rests on
 * every arm's batch being descending (the prefix property `OrderedMergeStrategy` documents). Leaving
 * that to each `rows()` implementation would make a silent mis-ordering in one arm corrupt the cursor of
 * a merge in another package. The identity tiebreak is also used when the cursor's row disappears,
 * so producer order cannot make surviving tied rows disappear from the next page.
 *
 * ## It declines every other capability
 *
 * No {@see QueriesRecords} (there is no `Builder`), no {@see WritesRecords} (a read-model is not a write
 * target), no {@see ResolvesRecord} (resolving one row to a projected {@see ResolvedRecord} needs a
 * projection this class cannot guess — an arm that has one declares the capability and implements it).
 * A declined capability is an answer.
 */
abstract class CollectionBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        $sortKey = $this->sortKey();
        $idKey = $this->idKey();

        $rows = Collection::make($this->rows($filters))
            ->sort(fn (mixed $left, mixed $right): int => $this->compare(
                data_get($left, $sortKey), (string) data_get($left, $idKey),
                data_get($right, $sortKey), (string) data_get($right, $idKey),
            ))
            ->values();

        // perPage + 1 so the paginator can tell the merge there is another page behind this batch.
        $slice = $rows->slice($this->resumeAt($rows, $cursor, $sortKey, $idKey))
            ->take($perPage + 1)
            ->values();

        return new CursorPaginator($slice, $perPage, Cursor::fromEncoded($cursor), [
            'parameters' => array_values(array_unique([$sortKey, $idKey])),
        ]);
    }

    /**
     * Every row this backing can yield for `$filters`, in any order — narrowing is the implementor's,
     * ordering and paging are this class's.
     *
     * @param  array<string, mixed>  $filters  the opaque `filter[...]` bag, forwarded verbatim
     * @return iterable<int, mixed>
     */
    abstract protected function rows(array $filters): iterable;

    /** The field rows are ordered and paged by — and the one a composite must merge these rows on. */
    protected function sortKey(): string
    {
        return 'id';
    }

    /** The field that uniquely identifies a row, used as the cursor's tiebreak. */
    protected function idKey(): string
    {
        return 'id';
    }

    /**
     * The index of the first row belonging to the page `$cursor` asks for.
     *
     * @param  Collection<int, mixed>  $rows
     */
    private function resumeAt(Collection $rows, ?string $cursor, string $sortKey, string $idKey): int
    {
        $decoded = Cursor::fromEncoded($cursor);

        if ($decoded === null) {
            return 0;
        }

        $parameters = $decoded->toArray();
        $lastId = $parameters[$idKey] ?? null;
        $lastSort = $parameters[$sortKey] ?? null;

        $exact = $rows->search(fn (mixed $row) => (string) data_get($row, $idKey) === (string) $lastId);

        if ($exact !== false) {
            return $exact + 1;
        }

        // The cursor's row is gone. Resume at the first row that ranks strictly BELOW it in the
        // descending (sort, id) order, so the page after it is still the page after it.
        foreach ($rows as $index => $row) {
            if ($this->compare(data_get($row, $sortKey), (string) data_get($row, $idKey), $lastSort, (string) $lastId) > 0) {
                return $index;
            }
        }

        return $rows->count();
    }

    /**
     * Descending order over `(sort, id)`, shared by sorting and deleted-row resumption.
     * `null` sorts lowest, matching the composite merge's own comparator.
     */
    private function compare(mixed $sort, string $id, mixed $otherSort, string $otherId): int
    {
        if ($sort === $otherSort) {
            return strcmp($otherId, $id);
        }

        if ($sort === null) {
            return 1;
        }

        if ($otherSort === null) {
            return -1;
        }

        return ($otherSort <=> $sort) ?: strcmp($otherId, $id);
    }
}
