<?php

namespace Splicewire\Beam\Read;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;

/**
 * The degenerate {@see ParticleHydrator} default (beam-write-pipeline ticket 13, DESIGN §9a/§9d): the
 * direct-from-source reader. A record's reconciled payload IS the data → `Data::from(reconcile($payload))`.
 * "Reader is the degenerate Hydrator" — one seam, not two.
 *
 * It needs NO `rushing/laravel-data-filters` dependency (that is the whole point of it living in
 * beam-core): it resolves the record's `Data` class straight off beam's own {@see AdminResourceRegistry}
 * (ADR-0156 retired the `SchemaDataResolver` inversion port — beam owns the registry, so it reads it
 * directly) and builds a typed `Data`, applying `ReadContext::$includes` as the serialization partial. It
 * does NOT compose list queries — that is the query-composing host binding's job, so {@see self::query()}
 * throws. This is niche until the deferred storage-collapse (DESIGN §9a); the host binds a
 * `DataFilterRecordHydrator` for real model-backed lists.
 */
class PayloadParticleReader implements ParticleHydrator
{
    public function __construct(private AdminResourceRegistry $resources) {}

    public function hydrate(Model|array|string $source, ReadContext $ctx): Data
    {
        if (! $source instanceof Model) {
            throw new InvalidArgumentException(
                'The payload reader hydrates a persisted record; pass a Model (a query-composing hydrator handles refs/arrays).',
            );
        }

        return $this->project($source, $ctx);
    }

    public function project(Model $record, ReadContext $ctx): Data
    {
        $dataClass = $this->dataClassFor($record);

        if ($dataClass === null) {
            throw new RuntimeException(
                'No Data class resolved for ['.$record::class.'] — register an #[ParticleResource] whose `model` matches this record type.',
            );
        }

        /** @var Data $data */
        $data = $dataClass::from($this->readableSource($record));

        return $ctx->includes === [] ? $data : $data->include(...$ctx->includes);
    }

    /**
     * The record → projection Data class map, resolved off the registered #[ParticleResource] definitions:
     * the first model-backed definition whose `model` the record is an instance of wins (its annotated
     * class IS `data`). Null when no definition claims the record type.
     *
     * @return class-string<Data>|null
     */
    private function dataClassFor(Model $record): ?string
    {
        foreach ($this->resources->all() as $definition) {
            if ($definition->model !== null && $record instanceof $definition->model) {
                /** @var class-string<Data> */
                return $definition->data;
            }
        }

        return null;
    }

    public function query(string $recordType, ReadContext $ctx): object
    {
        throw new BadMethodCallException(
            'The payload reader does not compose list queries; bind a query-composing ParticleHydrator (DataFilterRecordHydrator).',
        );
    }

    /**
     * A schema record's reconciled `payload` IS the data; a plain model projects from itself.
     */
    private function readableSource(Model $record): mixed
    {
        $payload = $record->getAttribute('payload');

        return is_array($payload) ? $payload : $record;
    }
}
