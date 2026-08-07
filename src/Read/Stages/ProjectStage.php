<?php

namespace Splicewire\Beam\Read\Stages;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ReadStage;
use Splicewire\Beam\Read\ReadPass;

/**
 * The shipped terminal (and, by default, only) read stage (DESIGN §9d): the direct-from-source projection.
 * A record's reconciled payload IS the data → `Data::from(readableSource($record))`, resolved to the record's
 * `Data` class straight off beam's own {@see ParticleResourceRegistry} (ADR-0156 retired the `SchemaDataResolver`
 * inversion port — beam owns the registry, so it reads it directly), then `ReadContext::$includes` applied as
 * the serialization partial.
 *
 * A host that composes a stage AFTER this one receives the built {@see Data} on the {@see ReadPass} and may
 * transform it (redaction, actor-scoped visibility).
 */
class ProjectStage implements ReadStage
{
    public function __construct(private ParticleResourceRegistry $resources) {}

    public function handle(ReadPass $pass, Closure $next): ReadPass
    {
        $dataClass = $this->dataClassFor($pass->record);

        if ($dataClass === null) {
            throw new RuntimeException(
                'No Data class resolved for ['.$pass->record::class.'] — register an #[ParticleResource] whose `model` matches this record type.',
            );
        }

        /** @var Data $data */
        $data = $dataClass::from($this->readableSource($pass->record));

        $pass->data = $pass->ctx->includes === [] ? $data : $data->include(...$pass->ctx->includes);

        return $next($pass);
    }

    /**
     * The record → projection Data class map, resolved off the registered #[ParticleResource] definitions:
     * the first model-backed definition whose `model` the record is an instance of wins (its annotated class
     * IS `data`). Null when no definition claims the record type.
     *
     * @return class-string<Data>|null
     */
    private function dataClassFor(Model $record): ?string
    {
        foreach ($this->resources->definitions() as $definition) {
            if ($definition->model !== null && $record instanceof $definition->model) {
                /** @var class-string<Data> */
                return $definition->data;
            }
        }

        return null;
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
