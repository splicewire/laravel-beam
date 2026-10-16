<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Versioning\MigrationStatus;
use Splicewire\Beam\Models\BeamParticle;

/** Shared detail/relation projection. Connection and schema registry are the host's realm-scoped bindings. */
final class CollectionRead
{
    public function __construct(private CollectionSchemaResolver $schemas, private StandaloneRecordQuery $records) {}

    public function find(string $stem, string $id, ?Authenticatable $actor): ?CollectionRecordData
    {
        return $this->many($stem, [$id], $actor)[$id] ?? null;
    }

    /**
     * Query a target collection once, including for a to-many relation. Never resolve ids globally.
     *
     * @param  list<string>  $ids
     * @return array<string, CollectionRecordData>
     */
    public function many(string $stem, array $ids, ?Authenticatable $actor): array
    {
        $definition = $this->schemas->resolve($stem);
        if ($ids === [] || ($actor === null && ! $definition->hasPublicRead())) {
            return [];
        }
        $models = $this->records->query()->whereIn('schema_ref', $this->schemas->recordRefs($stem))->whereKey(array_values(array_unique($ids)))->get();
        $result = [];
        foreach ($models as $model) {
            // Eloquent retrieved invokes the existing cheap reconcile-on-read seam first.
            $record = $this->project($definition, $model, $actor);
            if ($record !== null) {
                $result[$record->id] = $record;
            }
        }

        return $result;
    }

    public function project(CollectionDefinition $definition, BeamParticle $model, ?Authenticatable $actor): ?CollectionRecordData
    {
        $payload = (array) $model->payload;
        $status = $model->getAttribute('migration_status');
        $status = $status instanceof MigrationStatus ? $status->value : (string) $status;
        if ($actor === null && ($status !== MigrationStatus::Current->value || ! $definition->guestMatches($payload))) {
            return null;
        }

        return new CollectionRecordData(
            (string) $model->getKey(), $definition->stem(),
            (string) ($model->getAttribute('schema_id') ?? $model->schema_ref),
            $status, $actor === null ? $definition->publicFields($payload) : $payload,
        );
    }
}
