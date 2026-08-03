<?php

namespace Splicewire\Beam\Storage;

/**
 * A single materialized item on a {@see StorageDriver} — the domain-neutral unit the driver reads,
 * writes, and lists (beam-storage-driver charter; generalized from the schema-registry's per-`$id`
 * artifact into a per-`key` body).
 *
 * It is deliberately thin and non-domain: a `key` (the driver-relative address — for the Disk driver a
 * relative path, for the Particle driver the particle id), an optional `namespace` (the disk-grouping
 * coordinate the caller filed it under — {@see StorageDriver::list()} filters on it), the `body` (the
 * structured payload — the same array shape a beam-core `ParticleWriter` versions), and a
 * `modifiedAt` epoch used for {@see StorageDriver::staleness()} comparisons. No `type`, no `format`, no
 * URL — those are the paid consumer's (beam-ux) concern, kept out of the free port.
 */
class StorageItem
{
    /**
     * @param  array<string, mixed>  $body  the structured payload (the array a ParticleWriter stores).
     */
    public function __construct(
        public string $key,
        public array $body = [],
        public ?string $namespace = null,
        public ?int $modifiedAt = null,
    ) {}
}
