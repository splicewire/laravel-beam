<?php

namespace Splicewire\Beam\Read;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\Contracts\ReadStage;
use Splicewire\Beam\Read\Stages\ProjectStage;

/**
 * The degenerate {@see ParticleHydrator} default (beam-write-pipeline ticket 13, DESIGN §9a/§9d): the
 * direct-from-source reader. A record's reconciled payload IS the data → `Data::from(reconcile($payload))`.
 * "Reader is the degenerate Hydrator" — the shipped read chain is a SINGLE {@see ProjectStage}.
 *
 * Projection runs as a {@see ReadStage} chain over one {@see ReadPass}: the fine-grained read seam. A host
 * composes a pipe AFTER the project stage to redact fields or apply actor-scoped visibility WITHOUT replacing
 * the whole hydrator (the coarser read seam stays the {@see ParticleHydrator} port — swap in a query-composing
 * `DataFilterRecordHydrator`). Passing no `$stages` runs the shipped single-stage chain, so every existing
 * caller is unchanged.
 *
 * It needs NO `rushing/laravel-data-filters` dependency (that is the whole point of it living in beam-core):
 * the default {@see ProjectStage} resolves the record's `Data` class straight off beam's own
 * {@see ParticleResourceRegistry} (ADR-0156 retired the `SchemaDataResolver` inversion port — beam owns the
 * registry, so it reads it directly). It does NOT compose list queries — that is the query-composing host
 * binding's job, so {@see self::query()} throws.
 *
 * ## Deliberately NOT a registry — do not declare `#[IsRegistry]` on it
 *
 * It used to carry a manifest descriptor and was undescribed by registry-kernel ticket 07 D5, for the same
 * reason as {@see ParticleWriter}: `bind()` rather than a singleton, no `register()`, and a `$stages` list
 * that is constructor-seeded. Coarser extension is the {@see ParticleHydrator} port, which is a binding
 * swap — also not a registry. The stage-insertion hint the descriptor carried is the paragraph above.
 */
class PayloadParticleReader implements ParticleHydrator
{
    /**
     * @param  list<ReadStage>|null  $stages  the read chain; null ⇒ the shipped single {@see ProjectStage}
     *                                        built from this reader's own registry
     */
    public function __construct(private ParticleResourceRegistry $resources, private ?array $stages = null) {}

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
        $pass = (new Pipeline)
            ->send(new ReadPass($record, $ctx))
            ->through($this->stages ?? $this->defaultStages())
            ->then(fn (ReadPass $pass) => $pass);

        return $pass->data;
    }

    /**
     * The shipped default chain — a single {@see ProjectStage} built from this reader's own registry.
     *
     * @return list<ReadStage>
     */
    private function defaultStages(): array
    {
        return [new ProjectStage($this->resources)];
    }

    public function query(string $recordType, ReadContext $ctx): object
    {
        throw new BadMethodCallException(
            'The payload reader does not compose list queries; bind a query-composing ParticleHydrator (DataFilterRecordHydrator).',
        );
    }
}
