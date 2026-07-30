<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\MigratesSnapshotOnRestore;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Revisions\RecordsRevisions;
use Splicewire\Beam\Schema\SchemaId;

/**
 * A concrete, standalone schema record — the narrow-core row for apps that want a generic
 * store without minting their own model. It is entirely optional: the load-bearing piece
 * is {@see PersistsSchemaRecord}, which any domain model composes directly. Domain apps
 * with their own columns (kind, subject_id, title, …) use the trait on their own model
 * instead of extending this.
 *
 * As the schema-driven-CMS core (ADR-0138), a SchemaRecord is schema-typed AND
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
 * Backs the publishable `schema_records` table: `id` (uuid7), `schema_ref`, `schema_id`,
 * `migration_status`, `head_version`, `payload`, `meta`, timestamps. Populator-specific
 * facts (generation provenance, submission context) live in reference overlays keyed by
 * this record's id, never as columns here.
 */
class SchemaRecord extends Model implements MigratesSnapshotOnRestore, Versionable
{
    use PersistsSchemaRecord;
    use ReconcilesPayloadOnRead;
    use RecordsRevisions;
    use VersionableTrait;

    protected $table = 'schema_records';

    protected $fillable = [
        'schema_ref',
        'payload',
        'meta',
    ];

    /** The JSON column on this model holding the typed payload. */
    public function payloadColumn(): string
    {
        return 'payload';
    }

    /**
     * The beam write-pipeline persist seam, specialized for the base record: the schema-shaped content
     * IS this model's `payload` JSON column, so route it there rather than mass-filling attributes (the
     * default {@see PersistsSchemaRecord::fillFromSchemaPayload()} behaviour, which suits app models with
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
     * Apply a frozen snapshot back onto the live record through the normal write path.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->forceFill([
            'schema_ref' => $snapshot['schema_ref'] ?? null,
            'schema_id' => $snapshot['schema_id'] ?? null,
            'migration_status' => $snapshot['migration_status'] ?? null,
            'payload' => $snapshot['payload'] ?? null,
            'meta' => $snapshot['meta'] ?? null,
        ])->save();
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
