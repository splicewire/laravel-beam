<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Source\SourceWriteTier;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The generic schema-particle skeleton: turn an Eloquent model into a server-persisted,
 * schema-form-editable particle. This is the narrow, populator-agnostic core — nothing
 * here knows about generation, submission, or any one way the payload was produced.
 *
 * It composes Laravel's {@see HasUuids} (a time-ordered **uuid7** primary key — never a
 * hand-rolled v4 `Str::uuid()` creating hook) and casts the two generic columns:
 *
 *   payload (json) — the materialised object the schema form edits
 *   meta    (json) — schema-form-agnostic derived/annotation facts
 *
 * {@see self::extract()} is a present-but-inert seam for projecting derived rows (facets,
 * cards, search documents) out of the payload — any populator may override it.
 *
 * Provenance for a *particular* populator (generation grounding, submission context, …)
 * is NOT here: it composes OVER this trait as a reference overlay in the owning domain
 * package (composition, not inheritance). beam names no domain package — the dependency
 * runs one way (domain -> beam), never the reverse.
 *
 * @mixin Model
 */
trait PersistsBeamParticle
{
    use HasUuids;

    public function initializePersistsBeamParticle(): void
    {
        $this->mergeCasts([
            'payload' => 'array',
            'meta' => 'array',

            // Source provenance + lifecycle tier (sourced-particles ticket 06). Every particle model
            // carries them via this trait, so a shadow is the SAME row marked with its origin + tier —
            // no side-table. `source_tier` casts to the backed enum so `$record->source_tier->isShadow()`
            // reads directly; the shadow writer stamps them, the WriteGate reads the tier off the enum.
            'source_tier' => SourceWriteTier::class,
            'fetched_at' => 'datetime',
        ]);
    }

    /**
     * Seam for projecting derived rows (facets, cards, search documents, …) out of the
     * persisted payload. Inert by default; a host model overrides it when a record should
     * fan out into queryable child records.
     */
    public function extract(): void
    {
        //
    }

    /**
     * The beam write-pipeline persist seam ({@see ParticleWriter}): fill this
     * particle from a schema-shaped payload. The DEFAULT fills the model's own attributes — the app-model
     * case, where the payload IS the particle's columns. The base {@see BeamParticle}
     * overrides it to route the content into its `payload` JSON column instead. Keeping this a seam is
     * what makes the pipeline model-agnostic: the writer never has to know which shape it's persisting.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillFromSchemaPayload(array $payload): void
    {
        $this->fill($payload);
    }

    /**
     * The record's TYPE — the stem of its `schema_ref` binding ({@see SchemaId::recordType()}: a bare
     * stem as-is, a versioned `$id` stripped to its stem) — used by the write pipeline to resolve the
     * target schema for validation. Null when the record carries no schema binding (a plain app model),
     * for which the pipeline skips schema validation.
     */
    public function recordType(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? SchemaId::from($ref)->recordType() : null;
    }

    /**
     * The schema binding this record was written under (its `schema_ref` — a bare stem or a versioned
     * `$id`), reported on the {@see BeamParticlePersisted} event. Null for a
     * model that carries no `schema_ref`.
     */
    public function schemaBinding(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * The lifecycle tier this record sits in (sourced-particles ticket 06) — {@see SourceWriteTier::Local}
     * for a first-class record this tenant authored, or one of the two shadow tiers for a source-derived
     * one. Defaults to `Local` when the column is unset (a plain pre-ticket-06 row). The cast keeps the
     * stored string an enum, but a raw/unhydrated value is coerced here so callers always get the enum.
     */
    public function sourceTier(): SourceWriteTier
    {
        $tier = $this->getAttribute('source_tier');

        if ($tier instanceof SourceWriteTier) {
            return $tier;
        }

        return is_string($tier) && $tier !== ''
            ? SourceWriteTier::from($tier)
            : SourceWriteTier::Local;
    }

    /** True iff this record is a shadow (either shadow tier) rather than a locally-authored record. */
    public function isShadow(): bool
    {
        return $this->sourceTier()->isShadow();
    }
}
