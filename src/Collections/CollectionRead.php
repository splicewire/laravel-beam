<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * One evaluator for list/count/detail. Reconciliation may turn a current row pending,
     * so evaluate after hydration, then intersect rather than replacing the caller's scope.
     * This deliberately scans the collection; measured scaling belongs in the EP-34 proof.
     */
    public function publicScope(Builder $query, CollectionDefinition $definition): Builder
    {
        if (! $definition->hasPublicRead()) {
            return $query->whereRaw('1 = 0');
        }
        $ids = [];
        (clone $query)->reorder()->chunkById(200, function ($models) use ($definition, &$ids): void {
            foreach ($models as $model) {
                if ($this->project($definition, $model, null) !== null) {
                    $ids[] = $model->getKey();
                }
            }
        });

        return $query->whereKey($ids);
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
