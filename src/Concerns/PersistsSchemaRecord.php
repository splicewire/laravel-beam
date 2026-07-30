<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Events\SchemaRecordPersisted;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Write\RecordWriter;

/**
 * The generic schema-record skeleton: turn an Eloquent model into a server-persisted,
 * schema-form-editable record. This is the narrow, populator-agnostic core — nothing
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
trait PersistsSchemaRecord
{
    use HasUuids;

    public function initializePersistsSchemaRecord(): void
    {
        $this->mergeCasts([
            'payload' => 'array',
            'meta' => 'array',
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
     * The beam write-pipeline persist seam ({@see RecordWriter}): fill this
     * record from a schema-shaped payload. The DEFAULT fills the model's own attributes — the app-model
     * case, where the payload IS the record's columns. The base {@see SchemaRecord}
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
     * `$id`), reported on the {@see SchemaRecordPersisted} event. Null for a
     * model that carries no `schema_ref`.
     */
    public function schemaBinding(): ?string
    {
        $ref = $this->getAttribute('schema_ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }
}
