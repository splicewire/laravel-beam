<?php

namespace Splicewire\Beam\Particle\Backing\Merge;

use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use Splicewire\Beam\Particle\Backing\CompositeBacking;
use Splicewire\Beam\Particle\Backing\StreamsRecords;

/**
 * The default `ordered` plug of the `beam.particle.merge` socket (composite-backing ticket 03),
 * mirroring the grounding kernel's `OrderedUnionFusion` one tier over: a k-way merge on a sort key
 * every arm already pages by, DESCENDING, with a composite cursor that is an encoded
 * `{arm => armCursor|null}` map.
 *
 * ## The shape of one page
 *
 * Every arm is asked for `perPage` rows at its OWN current cursor (decoded out of the composite
 * cursor). Every arm's rows are assumed sorted by `$sortKey` descending — the assumption
 * {@see CompositeBacking} documents arms must honour — so the
 * `arm_count * perPage` candidates gathered this round merge into one descending list by a single
 * `usort()`, deterministic on ties by DECLARATION order of the arms and then arm-local position. The
 * first `perPage` of that merged list is the page.
 *
 * ⚠️ The tie-break is the arms' declaration order, not their key's alphabetical order. That is what
 * lets a consumer reproduce an existing in-memory merge exactly: `ReviewInbox::pending()` merged four
 * `->all()` collections in a fixed order and then `sortByDesc('createdAt')`, and PHP's stable sort left
 * equal-timestamp rows in that order. Sorting by key would have reproduced it by coincidence for those
 * four names and broken the first time an arm was renamed.
 *
 * ## Descending, and only descending
 *
 * Ordered mode ships one direction, matching the estate's `#[Sortable(default: true)]` convention
 * (`created_at desc`) and every list this composite is built for. A direction axis would have to be
 * honoured by each ARM's own paging as well as by this comparator — an arm handing back an ascending
 * batch breaks the prefix property below — so it is a declaration that belongs on the composite and its
 * arms together, not a flag on the comparator. Deferred with ranked mode (ticket 05).
 *
 * ## Why the per-arm cursor never needs arm-specific code
 *
 * Because an arm's own rows are sorted descending, the rows TAKEN from one arm into this page are
 * always a PREFIX of that arm's own returned batch (any row from an arm that ranks high enough to make
 * the page ranks at least as high as every row before it in that arm's own order). So "resume this arm
 * after the last row we took from it" is exactly what
 * {@see AbstractCursorPaginator::getCursorForItem()} already answers for that
 * arm's OWN `CursorPaginator` — the arm's own cursor convention (an `id` parameter, an Eloquent
 * sort-column set, anything) is opaque to this class and never needs to be. An arm that contributed
 * nothing to a page keeps its incoming cursor unchanged, so the identical batch is re-read (and
 * re-outranked) next page — a bounded, deliberate re-fetch of at most `perPage` rows rather than
 * special-casing "no progress this round".
 */
final class OrderedMergeStrategy implements MergeStrategy
{
    /**
     * The key {@see Cursor::toArray()} adds to every cursor's parameters. An arm may not be named this,
     * or its per-arm cursor would collide with the flag and decode as a boolean.
     */
    private const RESERVED_ARM_KEY = '_pointsToNextItems';

    public function merge(array $arms, array $filters, ?string $cursor, int $perPage, string $sortKey): CompositeCursorPaginator
    {
        if ($arms === []) {
            throw new InvalidArgumentException('Ordered merge needs at least one arm.');
        }

        $armKeys = array_keys($arms);
        $armOrder = array_flip($armKeys);
        $armCursors = $this->decodeArmCursors($cursor, $armKeys);

        $candidates = [];
        $armPages = [];
        $armHasMore = [];

        foreach ($arms as $key => $arm) {
            if ($key === self::RESERVED_ARM_KEY) {
                throw new InvalidArgumentException(
                    'Composite arm ['.self::RESERVED_ARM_KEY.'] is a reserved cursor key; name the arm after its `source` discriminator.'
                );
            }

            if (! $arm instanceof StreamsRecords) {
                throw new InvalidArgumentException(
                    "Composite arm [{$key}] does not implement StreamsRecords; every ordered arm must be able to page itself."
                );
            }

            $page = $arm->records($filters, $armCursors[$key] ?? null, $perPage);
            $armPages[$key] = $page;
            $armHasMore[$key] = $page->hasMorePages();

            $position = 0;
            foreach ($page->items() as $item) {
                $candidates[] = [
                    'arm' => $key,
                    'item' => $item,
                    'position' => $position++,
                    'sort' => data_get($item, $sortKey),
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => self::compare($b['sort'], $a['sort'])
            ?: ($armOrder[$a['arm']] <=> $armOrder[$b['arm']])
            ?: ($a['position'] <=> $b['position']));

        $taken = array_slice($candidates, 0, $perPage);
        $leftoverCandidates = count($candidates) > count($taken);

        $nextArmCursors = $armCursors;

        foreach ($taken as $row) {
            // Only the LAST row taken from an arm decides its resume point — the prefix property above
            // guarantees every earlier one from the same arm was taken too, so it is safe to overwrite
            // on every occurrence and simply let the final write win.
            $nextArmCursors[$row['arm']] = $armPages[$row['arm']]->getCursorForItem($row['item'], true)?->encode();
        }

        $hasMore = $leftoverCandidates || in_array(true, $armHasMore, true);

        return new CompositeCursorPaginator(
            items: array_map(static fn (array $row) => $row['item'], $taken),
            perPage: $perPage,
            cursor: $cursor !== null ? Cursor::fromEncoded($cursor) : null,
            forwardCursor: $hasMore ? new Cursor($nextArmCursors, true) : null,
            more: $hasMore,
        );
    }

    /**
     * `null`-aware, mixed-scalar comparison: `null` sorts lowest so an arm's missing sort value never
     * wins a merge it should lose.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        return $a <=> $b;
    }

    /**
     * The composite cursor IS the `{arm => armCursor|null}` map — one `Cursor` whose parameters are the
     * arm keys, so it encodes and decodes through Laravel's own base64/JSON envelope rather than a
     * second one nested inside it.
     *
     * Unknown keys are dropped and missing arms default to null, so adding, removing or renaming an arm
     * degrades an in-flight cursor to "that arm starts over" instead of throwing at a user holding a
     * page-3 URL.
     *
     * @param  list<string>  $armKeys
     * @return array<string, string|null>
     */
    private function decodeArmCursors(?string $cursor, array $armKeys): array
    {
        $decoded = array_fill_keys($armKeys, null);

        // `Cursor::fromEncoded()` answers null for anything it cannot read — not a string, not base64,
        // not JSON, or JSON without the `_pointsToNextItems` flag — so a hand-edited cursor is a first
        // page rather than an exception.
        $outer = Cursor::fromEncoded($cursor);

        if ($outer === null) {
            return $decoded;
        }

        foreach ($outer->toArray() as $key => $value) {
            if (array_key_exists($key, $decoded) && (is_string($value) || $value === null)) {
                $decoded[$key] = $value;
            }
        }

        return $decoded;
    }
}
