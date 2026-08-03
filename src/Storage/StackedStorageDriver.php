<?php

namespace Splicewire\Beam\Storage;

use InvalidArgumentException;
use Splicewire\Beam\Schema\BeamSchemaRegistry;

/**
 * The **ordered-sources composite** of the storage seam — the direct generalization of
 * {@see BeamSchemaRegistry}'s `['db','file']` shape (DB shadows file on read;
 * write lands in the first source) lifted from the schema-specific `SchemaRegistry` contract onto the
 * domain-neutral {@see StorageDriver} port (beam-storage-driver charter).
 *
 * The **default is `Stacked(Particle-primary, Disk-mirror)`** (charter): the {@see ParticleStorageDriver}
 * is the source-of-record and the {@see DiskStorageDriver} a **materialized projection**. So:
 *
 *  - `read` walks the tiers IN ORDER — the FIRST tier that has the key wins (primary shadows mirror),
 *    exactly as the schema registry's DB tier shadows its filesystem tier.
 *  - `write` is **materialize-on-save**: the primary tier persists (source-of-record), then EVERY
 *    secondary tier is mirrored with the same key/body/namespace. The primary's returned item is
 *    authoritative (it carries the source-of-record key).
 *  - `list` reads from the PRIMARY (the source-of-record's view is canonical; the mirror is a projection
 *    of it).
 *  - `staleness` is the PRIMARY's — an inbound `update-from-newer` sync asks "is this disk candidate
 *    newer than the source-of-record?".
 *
 * A file-primary co-dev stack and the S3 public-disk backing (charter) are alternate ORDERINGS of this
 * same composite — declared conformance points, not separate classes.
 */
class StackedStorageDriver implements StorageDriver
{
    /** @var array<int, StorageDriver> */
    protected array $tiers;

    public function __construct(StorageDriver ...$tiers)
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('StackedStorageDriver needs at least one tier.');
        }

        $this->tiers = $tiers;
    }

    public function read(string $key): ?StorageItem
    {
        foreach ($this->tiers as $tier) {
            $found = $tier->read($key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        // Source-of-record first — its returned item (and key) is authoritative.
        $primary = $this->tiers[0]->write($key, $body, $namespace);

        // Materialize-on-save: mirror to every secondary under the source-of-record's key.
        foreach (array_slice($this->tiers, 1) as $mirror) {
            $mirror->write($primary->key, $primary->body, $primary->namespace);
        }

        return $primary;
    }

    public function list(?string $namespace = null): array
    {
        return $this->tiers[0]->list($namespace);
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return $this->tiers[0]->staleness($key, $candidateModifiedAt);
    }
}
