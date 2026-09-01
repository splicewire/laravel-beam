<?php

namespace Splicewire\Beam\Read;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use Rushing\DataFilters\Facades\DataFilter;
use Spatie\LaravelData\Data;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Doctor\FilterablePromiseAudit;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
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
 * The PROJECTION half needs no host wiring at all: the default {@see ProjectStage} resolves the record's
 * `Data` class straight off beam's own {@see ParticleResourceRegistry} (ADR-0156 retired the
 * `SchemaDataResolver` inversion port — beam owns the registry, so it reads it directly).
 *
 * ## `query()` composes now — the two-host signal (particle-manifest-repatriation ticket 10, 2026-08-31)
 *
 * This docblock used to say the reader "needs NO `rushing/laravel-data-filters` dependency (that is the
 * whole point of it living in beam-core)" and that {@see self::query()} therefore throws unconditionally.
 * **Both halves were stale.** `rushing/laravel-data-filters` is a hard `require` of this package, not a
 * dev extra (beam's own test harness says so in as many words), and
 * {@see BeamServiceProvider::declareFilterResources()} registers a data-filters resource
 * for beam's OWN `hooks` particle. So beam was shipping a `filterable` resource, shipping the filter
 * wiring that resource needs, and then binding a default hydrator that could not honour either — every
 * host that did not bind over the default got a `BadMethodCallException` on `GET /api/v1/hooks`.
 *
 * The two hosts that noticed wrote the SAME six lines: `~/Herd/audiostud` as a subclass of this class
 * overriding only `query()`, and `~/Herd/splicewire-app` through tower's `DataFilterRecordHydrator`.
 * Two unrelated hosts replacing one default is a default that does not fit its own estate, so the six
 * lines descend here.
 *
 * **The degradation contract is unchanged.** A key with no data-filters resource behind it still raises
 * `BadMethodCallException` — the signal {@see ParticleFrameResourceHandler::indexQuery()}
 * catches to fall back to the plain query, and the one {@see FilterablePromiseAudit}
 * reports on. Only the *reason* moved: from "this reader never composes" to "nothing is registered under
 * this key", which is the condition that was actually true.
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

    /**
     * A list read: compose the `rushing/laravel-data-filters` builder registered under `$recordType` and
     * apply `$ctx->includes` to the EAGER-LOAD axis, so the ONE includes list drives both read axes —
     * `->with(...)` here, and the spatie serialization partial in {@see self::project()}.
     *
     * The registry lookup is the NULLABLE half of the miss pair on purpose. `$recordType` is a particle
     * key that may legitimately have no filter wiring behind it, and `DataFilter::query()` would answer
     * that with a `RegistryMiss` — a `RuntimeException`, which
     * {@see ParticleFrameResourceHandler::indexQuery()} does not catch, so an
     * unwired Frame index 500s instead of degrading. Stated rather than caught, and raised as the
     * `BadMethodCallException` the port has always declared for "no list query can be composed here".
     */
    public function query(string $recordType, ReadContext $ctx): object
    {
        if (DataFilter::tryResource($recordType) === null) {
            throw new BadMethodCallException(
                "No data-filters resource is registered under [{$recordType}], so no list query can be "
                    .'composed for it. A frame/particle resource may legitimately have no filter wiring; '
                    .'declare one in `config(\'data-filters.resources\')` if this list should be filterable.',
            );
        }

        $builder = DataFilter::query($recordType)->apply(app(Request::class));

        if ($ctx->includes !== []) {
            $builder->with($ctx->includes);
        }

        return $builder;
    }
}
