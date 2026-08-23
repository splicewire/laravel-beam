<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Rushing\Versioning\Contracts\RecordReconciler;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The canonical submission — beam-core's customer-zero {@see BeamParticle}. It IS a beam particle
 * (the submission populator, peer to generation and the frame editor): it composes beam's
 * {@see PersistsBeamParticle} — the narrow, populator-agnostic skeleton (uuid7 primary key via
 * HasUuids, `payload`/`meta` casts, the inert `extract()` seam) — and layers ONLY the form-runtime
 * facets over it: `capture_key`, `context`, and `schema_ref` (a plain string form-def `$id`/key straight
 * from the registry, NOT a SchemaIdentity object — that is what keeps beam schema-source-agnostic).
 *
 * As beam-core's customer-zero consumer it ALSO composes {@see ReconcilesPayloadOnRead} —
 * migrate-on-read ONLY (a capture under `waitlist/1` reads back upcast as the form schema evolves;
 * an expensive rung deferred to `pending`, an unmigratable one quarantined `failed`). It does NOT
 * compose Versionable (snapshot/HEAD/restore): a submission is immutable capture, not an editable
 * milestone-versioned doc.
 *
 * HOME + NAME (ADR-0138 amendment, reversing FC-15/ADR-0138's mistaken survivor): the canonical
 * submission is `BeamSubmission` homed in **beam-core base** (`Splicewire\Beam\Models`, beside
 * {@see BeamParticle} and {@see BeamSchema}) — the name ADR-0151 reserved for exactly this (the real
 * submission record, not the old created-but-never-read reference). The earlier collapse kept the
 * WRONG name (`FormSubmission`); the `laravel-beam-submissions` package was dissolved (BWP T11/T12),
 * so the submission MODEL is promoted into base, not left in the retired package.
 *
 * Storage follows execution context rather than pinning to `central`. With no tenant booted
 * (marketing leads, relay inbound) it resolves to the central/landlord DB; inside tenant context
 * (stancl swaps the default connection) circuit intake lands in the tenant's own schema, so
 * per-tenant submission payloads stay within tenant isolation. The `beam_submissions` table
 * therefore exists in both the central schema and every tenant schema — so this model pins NO
 * connection.
 *
 * Not final — a consuming app may extend it (add columns/relations, pin a connection) and point its
 * beam swappable-model config at the subclass (Spatie swappable-model pattern).
 */
class BeamSubmission extends Model
{
    use Deduplicates;
    use PersistsBeamParticle;
    use ReconcilesPayloadOnRead;

    protected $fillable = [
        'capture_key',
        'schema_ref',
        'payload',
        'context',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'payload' => 'array',
        'context' => 'array',
    ];

    /**
     * `beam_submissions`, via the single table-prefix seam {@see Beam::table()} (ADR-0151). A
     * property default cannot call config(), so the prefix is applied here.
     */
    public function getTable(): string
    {
        return Beam::table('submissions');
    }

    /** The stable morph alias — the permission-token prefix and polymorphic key for a submission. */
    public function getMorphClass(): string
    {
        return 'beam_submission';
    }

    /** The JSON column holding the captured form payload. */
    public function payloadColumn(): string
    {
        return 'payload';
    }

    /**
     * The beam write-pipeline persist seam ({@see ParticleWriter}): route the
     * schema-shaped content into the `payload` JSON column rather than mass-filling attributes —
     * mirroring the base {@see BeamParticle}. The `schema_ref`/`meta` bindings
     * are set on the instance BEFORE the write and are preserved.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillFromSchemaPayload(array $payload): void
    {
        $this->setAttribute('payload', $payload);
    }

    /**
     * The bound reconciler: the container's {@see RecordReconciler}. In a beam host that is the
     * host's fully-wired adapter (resolving the form stem's target from its schema registry); in a
     * headless beam app, beam-core's registry-latest default.
     */
    protected function payloadReconciler(): RecordReconciler
    {
        return app(RecordReconciler::class);
    }

    /**
     * A submission's record type is its `schema_ref` form binding resolved through
     * {@see SchemaId::recordType()} — a bare stem (`waitlist`) as-is, a versioned `$id`
     * (`waitlist/1`) stripped to its stem. Empty ref → null (the concern is a no-op).
     */
    protected function resolveRecordType(): ?string
    {
        $ref = $this->getAttribute('schema_ref');
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        return SchemaId::from($ref)->recordType();
    }
}
