<?php

namespace Splicewire\Beam\Data;

use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The READ projection for the `schemas` particle resource, and its declaration site.
 *
 * Registry-kernel ticket 65. Until it, {@see BeamSchema} carried no particle declaration at all, which
 * is why all three of its surfaces were hand-rolled controllers: undeclared, the resource could not be
 * named by `->beam()->inResource()`, did not appear in the Operator resources registry, and locked
 * nothing in the generated TypeScript / OpenAPI / client SDK.
 *
 * ## The declaration site is this class, not the model
 *
 * Ticket 65 §4 said to put the attribute on `BeamSchema` itself. That is not how this estate declares:
 * every `#[ParticleResource]` in the family sits on a **Data** class naming its model through
 * `backing:` (`TokenData` → `PersonalAccessToken`, `BeamUxEntryData` → `BeamUxEntry`). The model stays
 * a model; the Data class is the declared wire shape. Corrected here rather than followed.
 *
 * ## It is NOT read-only, and the filesystem is not the authority
 *
 * The canonical record is the DB row — the same relationship a beam-ux entry has to the scaffold it was
 * seeded from. The filesystem tier is a SYNC SOURCE that can move in either direction; it does not own
 * the record. So this resource declares an `input:` and accepts writes.
 *
 * What it does NOT declare is an update affordance, and that is the model's immutability rather than a
 * policy choice: {@see \Splicewire\Beam\Schema\DatabaseSchemaRegistry::register()} no-ops an identical
 * re-publish and REJECTS a changed shape under an existing `$id`, because a changed shape is a new
 * version. "Editing" a schema means minting the next `$id` — an ordinary create. See
 * {@see BeamSchemaInputData} for the derivation the write shares with `register()`.
 *
 * ## No CRUD verbs are mounted, deliberately
 *
 * `Route::particleResource()` is NOT called for this key. The tenant HTTP surface stays
 * `Splicewire\Tower\Api\V1\SchemaRegistryController` under the `beam.schemas.api_root` mount, because
 * none of the five generic verbs match what it serves: its `index` rolls versions up into STEMS across
 * two tiers (DB + committed filesystem artifacts) rather than listing rows, its `show` is addressed by
 * absolute `$id` rather than row id, its `store` is a freeze, and there is neither an update nor a
 * destroy. Mounting generic CRUD beside it would have created a FOURTH surface over one table, which is
 * the exact condition ticket 65 exists to end. Those routes declare themselves into this resource with
 * `->beam()->inResource('schemas')` instead — ticket 01's sanctioned form for a hand-rolled route whose
 * subject is a particle resource.
 */
#[ParticleResource(
    key: 'schemas',
    backing: BeamSchema::class,
    data: BeamSchemaData::class,
    input: BeamSchemaInputData::class,
    label: 'Schemas',
    group: 'Content',
    icon: 'file-json',
    section: 'authoring',
)]
#[TypeScript]
class BeamSchemaData extends Data
{
    public function __construct(
        #[NotInList]
        #[Description('Opaque row id. The addressable identity is `schemaId`, not this.')]
        public string $id,

        #[Column(label: 'Schema', sort: 0)]
        #[Description('The absolute, versioned `$id` this schema is addressed and `$ref`-targeted by.')]
        public string $schemaId,

        #[Column(label: 'Name', sort: 1)]
        #[Sortable(default: true)]
        #[Description('The stem — the `$id` minus its trailing version integer.')]
        public ?string $schemaName,

        #[Column(label: 'Version', sort: 2)]
        #[Sortable]
        #[Description('The version integer carried by the `$id`. Write-once: a changed shape gets a new one.')]
        public ?int $version,

        #[NotInList]
        #[Description('Structural fingerprint of the artifact. Equal fingerprints make a re-publish a no-op.')]
        public string $fingerprint,

        #[NotInList]
        #[Description('The frozen schema document itself. The artifact IS the schema.')]
        public array $artifact,

        #[Column(label: 'Frozen', sort: 3)]
        #[Sortable]
        public ?string $createdAt = null,
    ) {}

    public static function project(BeamSchema $model): self
    {
        return new self(
            id: (string) $model->getKey(),
            schemaId: $model->schemaId(),
            schemaName: $model->schema_name === null ? null : (string) $model->schema_name,
            version: $model->version === null ? null : (int) $model->version,
            fingerprint: (string) $model->fingerprint,
            artifact: is_array($model->artifact) ? $model->artifact : [],
            createdAt: $model->created_at?->toIso8601String(),
        );
    }
}
