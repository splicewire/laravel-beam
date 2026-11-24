<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\MigratesSnapshotOnRestore;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Revisions\RecordsRevisions;
use Splicewire\Beam\Schema\SchemaId;

/**
 * A concrete, standalone schema particle — the narrow-core row for apps that want a generic
 * store without minting their own model. It is entirely optional: the load-bearing piece
 * is {@see PersistsBeamParticle}, which any domain model composes directly. Domain apps
 * with their own columns (kind, subject_id, title, …) use the trait on their own model
 * instead of extending this.
 *
 * As the schema-driven-CMS core (ADR-0138), a BeamParticle is schema-typed AND
 * snapshot-versioned AND migrate-on-read AND restore-composes-migration out of the box —
 * no app-local wiring. THREE versioning disciplines coexist here, they do not merge:
 *
 *  - {@see RecordsRevisions} — activity-log undo/redo (change history);
 *  - {@see VersionableTrait} — durable named snapshots + restore (the HEAD pin lives on
 *    `head_version`);
 *  - {@see ReconcilesPayloadOnRead} — schema migration on read (the version column is
 *    `schema_id`, the status column `migration_status` — the trait defaults, so no
 *    overrides).
 *
 * {@see MigratesSnapshotOnRestore} composes the last two: restoring an older snapshot
 * runs its stale payload FORWARD through the bound reconciler before rehydration, so a
 * restore lands a current-shaped record.
 *
 * `schema_ref` stays the declared schema BINDING (a plain string; a bare stem or a
 * versioned `$id`); the record type is its stem. It is NOT aliased to `schema_id` — the
 * binding and the written-under id are different things, and conflating them corrupts
 * bare-stem tenant refs.
 *
 * Backs the publishable `beam_particles` table: `id` (uuid7), `schema_ref`, `schema_id`,
 * `migration_status`, `head_version`, `payload`, `meta`, timestamps. Populator-specific
 * facts (generation provenance, submission context) live in reference overlays keyed by
 * this record's id, never as columns here.
 *
 * The table name is resolved through the single beam table-prefix seam ({@see Beam::table()}) —
 * `beam_particles` under the default `beam_` prefix, `<host_prefix>particles` under a retrofit
 * host — NOT a hardcoded `$table` property (a property default can't call `config()`), so the
 * one prefix knob repoints this model with every other beam table.
 *
 * The morph value it writes into `beam_versions.versionable_type` is the stable alias
 * `beam_particle` ({@see getMorphClass()}), not the FQCN — so the eventual class rename (T07)
 * leaves existing version rows resolvable. The beam provider registers the alias additively
 * (`Relation::morphMap`, never `enforceMorphMap` — a host has many class-string morphs).
 *
 * @api
 */
class BeamParticle extends Model implements MigratesSnapshotOnRestore, Versionable
{
    use Deduplicates;
    use PersistsBeamParticle;
    use ReconcilesPayloadOnRead;
    use RecordsRevisions;
    use VersionableTrait;

    protected $fillable = [
        'schema_ref',
        'payload',
        'meta',
    ];

    /**
     * The physical table, resolved through the ONE beam table-prefix seam — `beam_particles` by
     * default. Not a `$table` property because a property default can't call `config()`; centralizing
     * it here means a retrofit host's prefix override reaches this model like every other beam table.
     */
    public function getTable(): string
    {
        return Beam::table('particles');
    }

    /**
     * The polymorphic morph value this record writes into `beam_versions.versionable_type` — the
     * durable alias `beam_particle`, NOT the FQCN. Registered additively in the beam provider's
     * morph map, so version rows survive the eventual class rename (T07) and resolve either way.
     */
    public function getMorphClass(): string
    {
        return 'beam_particle';
    }

    /** The JSON column on this model holding the typed payload. */
    public function payloadColumn(): string
    {
        return 'payload';
    }

    /**
     * The beam write-pipeline persist seam, specialized for the base record: the schema-shaped content
     * IS this model's `payload` JSON column, so route it there rather than mass-filling attributes (the
     * default {@see PersistsBeamParticle::fillFromSchemaPayload()} behaviour, which suits app models with
     * real columns). The `schema_ref` binding is set on the target BEFORE the write and is preserved.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillFromSchemaPayload(array $payload): void
    {
        $this->setAttribute($this->payloadColumn(), $payload);
    }

    /**
     * The bound reconciler: the container's {@see RecordReconciler}. Beam binds a
     * registry-backed default; a host overrides that binding with its richer adapter,
     * which transparently repoints this record's read path.
     */
    protected function payloadReconciler(): RecordReconciler
    {
        return app(RecordReconciler::class);
    }

    /**
     * A record's type is the STEM of its `schema_ref` binding (the `<base>/<name>`
     * portion sans any trailing version). A record with no `schema_ref` carries no
     * versioned schema, so the whole reconcile-on-read concern is a no-op for it.
     */
    protected function resolveRecordType(): ?string
    {
        return $this->recordTypeFor($this->getAttribute('schema_ref'));
    }

    /**
     * Freeze the record's schema-typed content into a snapshot: the binding, the
     * written-under id, the migration status, the payload, and the derived meta.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        return [
            'schema_ref' => $this->getAttribute('schema_ref'),
            'schema_id' => $this->getAttribute('schema_id'),
            'migration_status' => $this->getAttribute('migration_status'),
            'payload' => $this->getAttribute('payload'),
            'meta' => $this->getAttribute('meta'),
        ];
    }

    /**
     * The store outcome of the LAST restore reported via {@see recordRestoreOutcome()} (the base impl
     * reports its own save(); an override may report its own), or null if none has since
     * {@see clearRestoreOutcome()} last reset it. The revert recorder reads it (after clearing and
     * dispatching through the overridable restoreVersionSnapshot()) to tell a STORED write from a vetoed
     * one on a no-op revert — where the restored values already equal the live ones, so the model never
     * goes dirty and isDirty() cannot tell them apart.
     *
     * LAST-CALL state, single instance: the slot holds only the most recent report since the clear, so a
     * re-entrant restore on the SAME instance (a nested restoreVersionSnapshot() inside an override)
     * overwrites it — the recorder reads the last restore's outcome. Nested/re-entrant restores on one
     * instance are therefore unsupported; each revert is clear → one restore → read. The value is
     * transient per-instance bookkeeping, not persisted state; clone and serialization carry the plain
     * nullable bool harmlessly (a fresh instance simply reads null until its next reported restore).
     */
    protected ?bool $restoreStoredOutcome = null;

    /**
     * Apply a frozen snapshot back onto the live record through the normal write path, recording
     * whether that write STORED on {@see $restoreStoredOutcome}.
     *
     * Stays `: void` per the {@see Versionable} contract (widening it to `: bool` would fatal every
     * other `: void` implementer). The store outcome the revert path needs rides the slot instead of
     * the return type: a halting `saving`/`updating` listener (a guard, a policy, a tenancy veto) makes
     * `save()` return false WITHOUT persisting, so the slot carries the raw result for the caller to
     * read. Only a strict `false` means refused — a save()-override returning null is NOT a veto.
     *
     * The revert recorder dispatches HERE (not at a private worker), so a BeamParticle subclass that
     * OVERRIDES this method customises revert too. An override that calls parent inherits the reporting
     * for free. An override that persists the record ITSELF (and does NOT call parent) MUST report its
     * own save() result through {@see recordRestoreOutcome()} — that is the single seam that reaches the
     * slot; an override that reports nothing leaves the slot null, which the recorder reads as "unknown"
     * and treats as stored (the pre-amend behaviour for an override the recorder cannot see into). The
     * versioning store is the other caller: it wraps this in its OWN transaction and keys refusal off
     * its own save(), so once beam's lock takes the amended versioning tip a vetoed restore through the
     * store rolls back atomically — this method need not police that path.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $currentMeta = $this->getAttribute('meta') ?? [];
        if (isset($currentMeta['owner'])) {
            if (SchemaId::from($snapshot['schema_ref'] ?? '')->recordType()
                !== SchemaId::from($this->getAttribute('schema_ref'))->recordType()) {
                throw new LogicException('An owned particle cannot restore another schema binding.');
            }
            $snapshot['schema_ref'] = $this->getAttribute('schema_ref');
        }
        // Content restores never change principal or workflow authority, including ownerless workflows.
        if (is_array($snapshot['meta'] ?? null) || $currentMeta !== []) {
            $meta = $snapshot['meta'] ?? [];
            foreach (['owner', 'workflow'] as $managed) {
                unset($meta[$managed]);
                if (array_key_exists($managed, $currentMeta)) {
                    $meta[$managed] = $currentMeta[$managed];
                }
            }
            $snapshot['meta'] = $meta;
        }

        // Report the raw save() result (no (bool) cast) through the one slot-writing seam: the recorder
        // keys refusal off a strict `false`, so a save()-override returning null — or any non-`false`
        // value — normalises to `true` (stored) at the seam; only a strict `false` is the veto. The raw
        // (uncast) value matters so a falsey non-bool like `0` is not turned into `false`.
        $this->recordRestoreOutcome($this->forceFill([
            'schema_ref' => $snapshot['schema_ref'] ?? null,
            'schema_id' => $snapshot['schema_id'] ?? null,
            'migration_status' => $snapshot['migration_status'] ?? null,
            'payload' => $snapshot['payload'] ?? null,
            'meta' => $snapshot['meta'] ?? null,
        ])->save());
    }

    /**
     * Report the store result of a restore onto the outcome slot {@see restoreOutcome()} reads — the
     * SINGLE seam that writes it. The base {@see restoreVersionSnapshot()} reports its own save() here;
     * an override that persists the record itself calls this with its save() result so the recorder can
     * tell a stored write from a vetoed one. Only a strict boolean `false` is a refusal.
     *
     * Takes `mixed`, not `?bool`, and normalises here: this file has no `declare(strict_types=1)`, so a
     * `?bool` parameter would COERCE a falsey non-bool — a host `save()` override returning `0` (Eloquent's
     * save() is untyped) — to `false` and refuse a write that actually stored. A strict `=== false` keeps
     * only a real boolean false as the veto; every other reported value (`0`, `''`, a truthy, an explicit
     * null) means the write landed and normalises to `true` (stored). A TOTAL non-report never reaches
     * here, so the slot stays at the cleared `null` (unknown), which the recorder also reads as stored.
     */
    protected function recordRestoreOutcome(mixed $stored): void
    {
        $this->restoreStoredOutcome = $stored === false ? false : true;
    }

    /**
     * Reset the restore-outcome slot before a restore whose store result the caller will read via
     * {@see restoreOutcome()}. The recorder clears, dispatches through {@see restoreVersionSnapshot()},
     * then reads — so a stale outcome from an earlier restore can never be read as this one's.
     */
    public function clearRestoreOutcome(): void
    {
        $this->restoreStoredOutcome = null;
    }

    /**
     * The store outcome of the last {@see restoreVersionSnapshot()} since {@see clearRestoreOutcome()}:
     * `true` stored, `false` refused (a vetoed save), `null` unknown — reached ONLY when there was NO
     * report at all: an override bypassed the base impl and never called the seam. A REPORTED value,
     * including an explicit null, normalises to `true`/`false` at the seam and never leaves the slot null.
     * A caller treats null as stored (only a strict `false` is a refusal), so a missing report never newly
     * throws on a write that may have landed.
     */
    public function restoreOutcome(): ?bool
    {
        return $this->restoreStoredOutcome;
    }

    /**
     * Run a snapshot's stale payload FORWARD through the bound reconciler (cheap rungs
     * only) toward its stem's current version before a restore rehydrates it — so
     * restoring an OLD snapshot lands a current-shaped record. A payload no cheap rung
     * can migrate (pending/failed) is left as its preserved original; the record's read
     * path handles it after rehydration.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function migrateSnapshotForward(array $snapshot): array
    {
        $recordType = $this->recordTypeFor($snapshot['schema_ref'] ?? null);
        if ($recordType === null) {
            return $snapshot;
        }

        $storedId = $snapshot['schema_id'] ?? null;

        $outcome = $this->payloadReconciler()->reconcile(
            (array) ($snapshot['payload'] ?? []),
            is_string($storedId) ? $storedId : null,
            $recordType,
        );

        // Only a cheap-migrated candidate carries a fresh payload; already-current is a
        // no-op, and pending/failed leave the original preserved.
        if ($outcome->payload !== null) {
            $snapshot['payload'] = $outcome->payload;
            if ($outcome->versionId !== null) {
                $snapshot['schema_id'] = $outcome->versionId;
            }
        }

        return $snapshot;
    }

    /**
     * Derive the record type from a `schema_ref` binding — null for an empty/absent ref,
     * otherwise the binding's {@see SchemaId::recordType()} (bare stem as-is, versioned id
     * stripped to its stem). Shared by the read path ({@see resolveRecordType()}) and the
     * snapshot-restore path ({@see migrateSnapshotForward()}).
     */
    protected function recordTypeFor(mixed $schemaRef): ?string
    {
        if (! is_string($schemaRef) || $schemaRef === '') {
            return null;
        }

        return SchemaId::from($schemaRef)->recordType();
    }
}
