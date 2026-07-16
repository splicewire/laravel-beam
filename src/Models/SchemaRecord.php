<?php

namespace Schemastud\Beam\Models;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Beam\Concerns\PersistsSchemaRecord;

/**
 * A concrete, standalone schema record — the narrow-core row for apps that want a generic
 * store without minting their own model. It is entirely optional: the load-bearing piece
 * is {@see PersistsSchemaRecord}, which any domain model composes directly. Domain apps
 * with their own columns (kind, subject_id, title, …) use the trait on their own model
 * instead of extending this.
 *
 * Backs the publishable `schema_records` table: `id` (uuid7), `schema_ref`, `payload`,
 * `meta`, timestamps. Populator-specific facts (generation provenance, submission context)
 * live in reference overlays keyed by this record's id, never as columns here.
 */
class SchemaRecord extends Model
{
    use PersistsSchemaRecord;

    protected $table = 'schema_records';

    protected $fillable = [
        'schema_ref',
        'payload',
        'meta',
    ];
}
