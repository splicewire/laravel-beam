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
     * DEAD — measured, not suspected (particle-contribution-seam ticket 09, 2026-08-21). Enumerated live in
     * four hosts (splicewire-app 30 registrations, audiostud 22, tower 11, beam starter 9): ZERO fall through
     * to the hydrator arm that reaches this scan, because every declaration carries `data:` or `project:`, and
     * `ParticleController::projectRecord()` consults the hydrator ONLY when both are null. `ParticleFrameResourceHandler`
     * never consults it at all — it reads `$definition->data` directly. Also zero model collisions in those
     * four hosts, so the "first-match-wins race" this scan was suspected of has no live instance either.
     *
     * NOT deleted here on purpose: `dataClassFor()` is private to `project()`, and `project()` is a
     * {@see \Splicewire\Beam\Read\Contracts\ParticleHydrator} method. Narrowing that port to `query()` + `hydrate()`
     * — the separate read-repair effort ticket 08 diagnosed — removes `project()` and takes this scan with it.
     * Deleting half of that here would hand that effort a partially-narrowed port.
     *
     * The `instanceof` key scheme lost regardless: ticket 09 settled the record → Data map as a DECLARED
     * `schemaRef` binding (a `SchemaBindingIndex`), because one PHP class can carry many `schema_ref`-discriminated
     * record types and `instanceof` cannot discriminate them.
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
