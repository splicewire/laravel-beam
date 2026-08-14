<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Facades\Beam;

/**
 * A registered schema version — one frozen row of the runtime, DB-backed schema registry
 * (`beam_schemas`, the `db` tier {@see \Splicewire\Beam\Schema\DatabaseSchemaRegistry} reads/writes).
 *
 * Promoted to a first-class model (reverses ADR-0151's original "no BeamSchema model" — see that ADR's
 * amendment). The reasons the model was deferred are gone: it need not mirror on-disk schema files
 * (the filesystem tier is a *separate* source), and the `BeamSchema` / `SchemaRegistry` name no longer
 * collides (the registry is `…SchemaRegistry`, this is the record). As a model it gains a policy seam
 * (schemas may be permissioned), the beamux central/tenant facade-map ergonomics, and ordinary Eloquent
 * access — while staying, at bottom, "just JSON with a few indexed attributes": the `artifact` IS the
 * schema document, the rest are the addressable `$id`/`stem`/`version` + the write-once `fingerprint`.
 *
 * WRITE-ONCE. A schema version is immutable: {@see DatabaseSchemaRegistry::register()} inserts a new row
 * or no-ops on an identical re-publish, and REJECTS a changed shape (a new shape needs a new `$id`).
 * There is deliberately no update path — so the "gate the write before it half-lands" concern that
 * attends a model with a pivot does not arise here; a schema is created whole, never mutated.
 */
class BeamSchema extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'artifact' => 'array',
        'version' => 'integer',
    ];

    public function getTable(): string
    {
        return Beam::table('schemas');
    }

    public function getMorphClass(): string
    {
        return 'beam_schema';
    }

    /** The absolute, versioned `$id` this row is addressed by (the unique key). */
    public function schemaId(): string
    {
        return (string) $this->schema_id;
    }
}
