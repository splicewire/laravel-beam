<?php

namespace Splicewire\Beam\Data;

use InvalidArgumentException;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The WRITE DTO for the `schemas` particle resource — the `input:` slot of {@see BeamSchemaData}.
 *
 * A schema row is NOT authored column-by-column: the artifact IS the schema, and every other column
 * (`schema_id`, `schema_name`, `version`, `fingerprint`) is DERIVED from the document's own `$id`.
 * So this DTO accepts one field — the document — and `toModelAttributes()` derives the rest exactly
 * as {@see DatabaseSchemaRegistry::register()} does, from the same two helpers.
 *
 * ⚠️ **Write-once is a property of the `$id`, not of the resource.** Registering a NEW `$id` is an
 * ordinary create (that is what the registry's `store`/`freeze` surface has always done). What cannot
 * happen is an in-place change of shape under an EXISTING `$id`: the registry no-ops an identical
 * re-publish and throws {@see SchemaRegistryConflict} on a changed
 * one, because a changed shape needs a new version. A Frame-tier "edit" therefore means *mint the next
 * version*, not *mutate this row* — which is why {@see BeamSchemaData} declares no update affordance.
 *
 * The filesystem tier does not contradict this. It is a SYNC SOURCE, not the authority: the canonical
 * record is the DB row, the same way a beam-ux entry's canonical record is its row rather than the
 * scaffold it was seeded from.
 *
 * `Data` here is beam's OWN `Splicewire\Beam\Data\Data` — the sibling class in this
 * namespace — not `Spatie\LaravelData\Data`. The import is absent on purpose: beam ships that base
 * class so every DTO answers `::jsonSchema()` through the host's configured generator (`66e2dff`),
 * and a particle-declared DTO inside beam that skipped it was the one shape beam's own doctrine
 * could not describe.
 */
class BeamSchemaInputData extends Data implements MapsToModelAttributes
{
    /**
     * @param  array<string, mixed>  $artifact  The complete authored schema document, `$id` included.
     */
    public function __construct(
        public array $artifact = [],
    ) {}

    /**
     * The write map: DTO field ⇒ model column.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $id = $this->artifact['$id'] ?? null;

        // The same guard `register()` opens with, and for the same reason: an artifact without an `$id`
        // has no identity, so there is no row it could be. Rejected here rather than written as null.
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        return [
            'schema_id' => $id,
            'schema_name' => SchemaId::from($id)->stem(),
            'version' => SchemaId::from($id)->version(),
            'fingerprint' => SchemaFingerprint::of($this->artifact),
            'artifact' => $this->artifact,
        ];
    }
}
