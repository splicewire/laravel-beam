<?php

namespace Splicewire\Beam\Particle\Backing\Merge;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Splicewire\Beam\Particle\Backing\CompositeBacking;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;

/**
 * The composite backing's merge SOCKET (`beam.particle.merge.<handle>`), mirroring
 * `Splicewire\Grounding\Fusion\GroundingFusion` one tier over: an ordered list of arms in, one paged,
 * merged stream out. Beam owns this socket plus the {@see OrderedMergeStrategy} default plug; a satellite
 * or tower package plugs a different strategy (ranked fusion, composite-backing ticket 05) under its own
 * handle through {@see MergeStrategyRegistry} without beam knowing it exists.
 *
 * ⚠️ The charter spelled the root `particle.merge`. It is rooted `beam.particle.merge` for the reason
 * {@see MergeStrategyRegistry} records: every sibling registry beam owns is rooted under `beam.`, the
 * popcorn index routes a key to a registry by ROOT PREFIX, and an unprefixed `particle.merge` would
 * claim a segment of the estate keyspace no package owns. A {@see CompositeBacking}
 * still names its plug by the bare handle (`ordered`); the root is stamped on by the registry.
 *
 * Composite-backing PRD: only ORDERED mode ships in ticket 03. This interface is written for both — a
 * ranked plug pages by offset over a materialized ranking rather than per-arm cursors, and still answers
 * `merge()` with the same signature; it simply ignores `$sortKey` and interprets the cursor as an offset.
 */
interface MergeStrategy
{
    /**
     * One page of the merged stream across every arm.
     *
     * @param  array<string, StreamsRecords>  $arms  every arm, ALREADY RESOLVED, keyed by its `source`
     *                                               discriminator — the same keys a caller passes as
     *                                               `filters['source']` to {@see ResolvesRecord::resolve()}
     * @param  array<string, mixed>  $filters  the request's opaque `filter[...]` bag, forwarded to every
     *                                         arm verbatim
     * @param  string|null  $cursor  the composite's own encoded cursor, or null for the first page
     * @param  string  $sortKey  the field every arm pages by, read off each yielded row with `data_get()`
     */
    public function merge(array $arms, array $filters, ?string $cursor, int $perPage, string $sortKey): CursorPaginator;
}
