<?php

namespace Splicewire\Beam\Data;

use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Collections\CollectionBacking;
use Splicewire\Beam\Collections\CollectionInputData;
use Splicewire\Beam\Collections\CollectionRead;
use Splicewire\Beam\Collections\CollectionRecordData;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Collections\PublicRecordData;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Schema\SchemaId;

#[ParticleResource(key: 'records', backing: CollectionBacking::class, input: CollectionInputData::class,
    label: 'Records', readPolicy: AuthenticatedReadPolicy::class,
    publicRead: PublicRecordData::class, editData: RecordData::class)]
class RecordData extends CollectionRecordData
{
    public static function fromModel(BeamParticle $model): self
    {
        return self::project($model);
    }

    public static function project(BeamParticle $model): self
    {
        $definition = app(CollectionSchemaResolver::class)->resolve(SchemaId::from($model->schema_ref)->stem());

        return new self((string) $model->getKey(), $definition->stem(), (string) $model->schema_id,
            (string) $model->migration_status, (array) $model->payload, $definition->schema);
    }

    public static function prepare(BeamParticle $model, mixed $input, mixed $actor = null): void
    {
        $model->schema_ref = app(CollectionBacking::class)->definition()->schema['$id'];
    }

    public static function publicScope(Builder $query): Builder
    {
        return app(CollectionRead::class)->publicScope($query, app(CollectionBacking::class)->definition());
    }
}
