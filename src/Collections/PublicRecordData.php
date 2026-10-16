<?php

namespace Splicewire\Beam\Collections;

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaId;

/** The fixed Public Read envelope delegates field visibility to the current Collection. */
class PublicRecordData extends CollectionRecordData
{
    public static function fromModel(BeamParticle $model): self
    {
        $definition = app(CollectionSchemaResolver::class)->resolve(SchemaId::from($model->schema_ref)->stem());
        $record = app(CollectionRead::class)->project($definition, $model, null);
        abort_if($record === null, 404);

        return new self($record->id, $record->collection, $record->schemaRef, $record->migrationStatus, $record->fields, $definition->schema);
    }
}
