<?php

namespace Splicewire\Beam\Storage;

use Splicewire\Beam\Schema\BeamSchemaRegistry;

/**
 * The **domain-neutral storage port** (beam-storage-driver charter; ADR-0165 "two trees" — this is the
 * *disk/persistence* seam, never the URL). It is FREE **beam-core** (`Splicewire\Beam\Storage\`, NOT
 * `BeamUx*`): beam-core owns ONE storage seam with two consumers — the schema registry it was
 * **generalized from** ({@see BeamSchemaRegistry}, already an ordered-sources,
 * DB-shadows-file, `Stacked(DB, filesystem)` shape) and the paid beam-ux authoring engine as its
 * **second** consumer. It therefore must NOT depend up on any paid tier.
 *
 * The port is four operations over a `key` (a driver-relative address) carrying a structured `body`
 * (the same array a `ParticleWriter` versions), optionally grouped under a `namespace` (the
 * disk-grouping coordinate, ADR-0165):
 *
 *  - `read` — the item at a key, or null.
 *  - `write` — persist a body at a key; returns the written {@see StorageItem} (materialize-on-save is
 *    the caller's default, done by writing to the primary + mirror through a {@see StackedStorageDriver}).
 *  - `list` — every item, optionally filtered to one `namespace`.
 *  - `staleness` — compare a candidate's `modifiedAt` against the stored item's, so an operator's
 *    explicit inbound sync (`update-from-newer`, config-gated OFF by default) can decide whether disk is
 *    newer than the source-of-record. Returns >0 when the candidate is newer, 0 when equal/unknown, <0
 *    when the stored item is newer.
 *
 * Three drivers implement it: {@see ParticleStorageDriver} (DB source-of-record over beam-core
 * particles), {@see DiskStorageDriver} (a filesystem projection), and {@see StackedStorageDriver} (the
 * default `Stacked(Particle-primary, Disk-mirror)` — DB is source-of-record, disk a materialized
 * projection). **Declared conformance points, not built here:** a file-primary co-dev stack and the S3
 * public-disk backing are alternate `Stacked` orderings on this same seam.
 */
interface StorageDriver
{
    /** The item at `$key`, or null when absent. */
    public function read(string $key): ?StorageItem;

    /**
     * Persist `$body` at `$key` under an optional disk-grouping `$namespace`; returns the written item.
     *
     * @param  array<string, mixed>  $body
     */
    public function write(string $key, array $body, ?string $namespace = null): StorageItem;

    /**
     * Every item this driver holds, optionally filtered to one `$namespace`.
     *
     * @return array<int, StorageItem>
     */
    public function list(?string $namespace = null): array;

    /**
     * The staleness of a `$candidateModifiedAt` against the item stored at `$key`:
     * >0 candidate is newer, 0 equal/absent/unknown, <0 the stored item is newer.
     */
    public function staleness(string $key, int $candidateModifiedAt): int;
}
