<?php

namespace Splicewire\Beam\Write;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pipeline\Pipeline;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Source\ParticleShadower;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\Stages\AuthorizeStage;
use Splicewire\Beam\Write\Stages\DedupeStage;
use Splicewire\Beam\Write\Stages\EmitStage;
use Splicewire\Beam\Write\Stages\PersistStage;
use Splicewire\Beam\Write\Stages\ValidateStage;

/**
 * The single, model-agnostic beam-core write pipeline (beam-write-pipeline ticket 03, DESIGN §3a) —
 * the one path every write surface rides instead of each controller hand-rolling
 * `validate → authorize → fill → save → sync-relations`.
 *
 * The path is a CHAIN of {@see WriteStage} pipes over one {@see WriteContext} — the chainable write seam the
 * original particle design called for. The shipped default chain runs, in order:
 *
 *   1. {@see AuthorizeStage} — deny-by-default {@see WriteGate}; refused ⇒ {@see WriteNotAuthorized}, nothing
 *      persists;
 *   2. {@see ValidateStage} — payload vs. the record type's resolved target schema via the
 *      {@see AcceptanceGate}; non-conforming ⇒ {@see PayloadRejected}, nothing persists (skipped when the
 *      type has no registered schema: a plain app model validates at its own DTO boundary);
 *   3. {@see DedupeStage} — stamp the capture's match key and act on the target schema's
 *      `x-beam-dedupe` (beam-facade ticket 66); a no-op when the schema carries no keyword, which is
 *      every schema in the estate that has not opted in. It sits AFTER the two gates deliberately: an
 *      unauthorized duplicate is a 403 and an invalid duplicate is a 422, so a dedupe verdict never
 *      preempts a gate;
 *   4. {@see PersistStage} — fill through the model's {@see PersistsBeamParticle} seam and save (to ANY
 *      trait-bearing model, which keeps its own table), then run the optional after-persist hook (relation
 *      syncs live HERE, deliberately not their own stage — DESIGN §3a);
 *   5. {@see EmitStage} — one {@see BeamParticlePersisted}, the single post-persist signal every write path
 *      shares (suppressible for a light shadow-write).
 *
 * A caller reads the WRITTEN MODEL off this method's return value, never off the instance it passed in:
 * under `x-beam-dedupe`'s `ignore` mode the returned model is the row that MATCHED, a different object
 * from the one handed in, and the two must stay indistinguishable to the caller.
 *
 * A host layers a cross-cutting concern (audit, tenancy stamp, rate-limit, idempotency) by passing its own
 * ordered `$stages` list — inserting a pipe into the chain, not wrapping or subclassing this writer. Passing
 * no list runs the shipped default chain, so every existing caller (and the 4-arg constructor) is unchanged.
 *
 * It is named `ParticleWriter`, NOT `BeamParticleWriter`: it writes any persisting particle, not only the
 * base {@see BeamParticle}.
 *
 * ## Deliberately NOT a registry — do not declare `#[IsRegistry]` on it
 *
 * It used to carry a manifest descriptor and was undescribed by registry-kernel ticket 07 D5. It is bound
 * with `bind()`, not as a singleton, and has no `register()` — nothing accumulates into it, so a second
 * resolution starts from the same shipped defaults. The `$stages` list is CONSTRUCTOR-SEEDED, which is a
 * composition seam and not a keyspace: you insert a pipe by constructing this writer differently, not by
 * registering into a live object. The stage-ordering hint the descriptor carried is the paragraph above,
 * kept here on purpose so undescribing it lost nothing.
 */
class ParticleWriter
{
    /**
     * @param  list<WriteStage>|null  $stages  the write chain to run; null ⇒ the shipped default chain built
     *                                         from this writer's own collaborators (so a per-call gate — e.g.
     *                                         a public-intake host binding — still flows into authorization)
     */
    public function __construct(
        private WriteGate $gate,
        private SchemaTargetResolver $targets,
        private AcceptanceGate $acceptance,
        private Dispatcher $events,
        private ?array $stages = null,
    ) {}

    /**
     * Write `$payload` to `$target`, returning the persisted model.
     *
     * @param  Model|class-string<Model>  $target  a model instance (pre-set with its schema binding) or
     *                                             a model class to instantiate
     * @param  array<string, mixed>  $payload  the schema-shaped content to validate and persist
     * @param  mixed  $actor  the authenticated actor, or null for an anonymous write
     * @param  (Closure(Model): void)|null  $after  an after-persist hook (relation syncs)
     * @param  bool  $emit  emit the post-persist {@see BeamParticlePersisted} signal (default true — the
     *                      normal write). A LIGHT shadow-write ({@see ParticleShadower}
     *                      cache tier, sourced-particles ticket 06) passes `false` to SUPPRESS the adoption
     *                      side-effects the event drives (embedding jobs, indexing, notification); the full
     *                      pipeline — including this signal — fires ONCE later on `promote()`. Additive and
     *                      default-true, so every existing caller is unaffected.
     *
     * @throws WriteNotAuthorized the gate refused the write (nothing persisted)
     * @throws PayloadRejected the payload does not conform to its target schema (nothing persisted)
     */
    public function write(Model|string $target, array $payload, mixed $actor = null, ?Closure $after = null, bool $emit = true): Model
    {
        $model = $target instanceof Model ? $target : new $target;
        $context = new WriteContext($model, $payload, $actor, $after, $emit);

        return (new Pipeline)
            ->send($context)
            ->through($this->stages ?? $this->defaultStages())
            ->then(fn (WriteContext $context) => $context)
            ->model;
    }

    /**
     * The shipped default chain — authorize → validate → dedupe → persist → emit — built from THIS writer's own
     * collaborators (not resolved fresh from the container) so a caller that constructs the writer with a
     * bespoke gate (e.g. the public-intake host binding) has that gate govern authorization.
     *
     * @return list<WriteStage>
     */
    private function defaultStages(): array
    {
        return [
            new AuthorizeStage($this->gate),
            new ValidateStage($this->targets, $this->acceptance),
            new DedupeStage($this->targets),
            new PersistStage,
            new EmitStage($this->events),
        ];
    }
}
