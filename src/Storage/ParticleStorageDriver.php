<?php

namespace Splicewire\Beam\Storage;

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Write\AsSystemWriter;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The **DB source-of-record** tier of the storage seam (beam-storage-driver charter) — the Particle half
 * of the default {@see StackedStorageDriver}, and the generalization of the schema registry's DB tier.
 * A `key` is a {@see BeamParticle} id; the `body` rides the particle's versioned, migrate-on-read
 * `payload` column, written through beam-core's shared {@see ParticleWriter} (NO fork of the write
 * pipeline — same discipline beam-ux's own entry uses). The disk-grouping `namespace` (ADR-0165) is
 * kept out of the versioned payload and stored on the particle's `meta` — it addresses the item, it is
 * not part of the body.
 *
 * `write` with an empty `$key` mints a fresh particle and returns its id as the item key (the
 * caller-provided key wins on re-write, so materialize-on-save from a {@see StackedStorageDriver} can
 * round-trip a stable key). `list($namespace)` queries `meta->namespace`. `staleness` compares
 * `updated_at`.
 */
class ParticleStorageDriver implements StorageDriver
{
    /**
     * Takes either a {@see ParticleWriter} or a **closure resolving one per write**.
     *
     * The closure form exists because {@see ParticleWriter} is bound `bind()`, not `singleton()`,
     * precisely so a caller can rebind {@see WriteGate} for the
     * duration of a flow — {@see AsSystemWriter} is the whole reason that
     * matters. A **long-lived** holder that captures the writer in its constructor pins whichever gate
     * was bound at construction, and a later rebind can never reach it: `BeamUxServiceProvider` binds
     * `StorageDriverResolver` as a singleton, so on any host that resolved it before
     * `splicewire:beam:seed` ran, the seeder wrote through a driver still holding the deny-by-default
     * gate and every seeded page failed with "the write gate refused a write". Same defect class as
     * beam-docs-satellite tickets 07 and 08, one level further up the graph — and {@see BeamManager}'s
     * own docblock already states the rule ("collaborators resolve per call") that this violated.
     *
     * A caller that has ALREADY chosen its gate for one specific write — `BeamUxEntryBodyController`
     * hands in a request-scoped writer — passes the instance and gets exactly that writer.
     *
     * @param  ParticleWriter|(\Closure(): ParticleWriter)  $writer
     */
    public function __construct(protected ParticleWriter|\Closure $writer) {}

    protected function writer(): ParticleWriter
    {
        return $this->writer instanceof ParticleWriter ? $this->writer : ($this->writer)();
    }

    public function read(string $key): ?StorageItem
    {
        $particle = BeamParticle::find($key);
        if ($particle === null) {
            return null;
        }

        return $this->itemFrom($particle);
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $particle = $key !== '' ? BeamParticle::find($key) ?? new BeamParticle : new BeamParticle;

        // The disk-grouping coordinate addresses the item; it is NOT part of the versioned body, so it
        // rides `meta` (untouched by the schema payload) rather than the payload the writer versions.
        $meta = (array) ($particle->meta ?? []);
        $meta['namespace'] = $namespace;
        $particle->meta = $meta;

        // The body lands through the SHARED write pipeline — versioning + migrate-on-read intact.
        $written = $this->writer()->write($particle, $body);

        return $this->itemFrom($written instanceof BeamParticle ? $written : $particle->refresh());
    }

    public function list(?string $namespace = null): array
    {
        $query = BeamParticle::query();

        if ($namespace !== null) {
            $query->where('meta->namespace', $namespace);
        }

        return $query->get()->map(fn (BeamParticle $p): StorageItem => $this->itemFrom($p))->all();
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        $particle = BeamParticle::find($key);
        if ($particle === null || $particle->updated_at === null) {
            return 0;
        }

        return $candidateModifiedAt <=> $particle->updated_at->getTimestamp();
    }

    protected function itemFrom(BeamParticle $particle): StorageItem
    {
        $namespace = $particle->meta['namespace'] ?? null;

        return new StorageItem(
            (string) $particle->getKey(),
            (array) ($particle->payload ?? []),
            is_string($namespace) ? $namespace : null,
            $particle->updated_at?->getTimestamp(),
        );
    }
}
