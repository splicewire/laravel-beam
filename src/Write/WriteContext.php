<?php

namespace Splicewire\Beam\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Events\BeamParticlePersisted;

/**
 * The mutable passable that rides the {@see ParticleWriter} stage pipeline (beam-write-pipeline ticket 03,
 * DESIGN §3a — "restore the chainable seam"). ONE context threads every stage: it carries the write inputs
 * (target model, payload, actor, after-hook, emit flag) and accumulates the outcome as stages run.
 *
 * It exists so the write flow is a chain of {@see Contracts\WriteStage} pipes — authorize, validate, persist,
 * emit — instead of one flat method a host can only wrap or subclass. A host inserts a cross-cutting stage
 * (audit, tenancy-stamp, rate-limit) by registering it in the pipeline, not by re-hand-rolling the path.
 *
 * The record-type and binding lookups the schema/event stages need live here (a trait-bearing model exposes
 * them; any other model has none), so no stage re-derives them and they are resolved off the model once.
 */
class WriteContext
{
    /**
     * @param  Model  $model  the target being written (already instantiated from the class-string if given one)
     * @param  array<string, mixed>  $payload  the schema-shaped content to validate and persist
     * @param  mixed  $actor  the authenticated actor, or null for an anonymous write
     * @param  (Closure(Model): void)|null  $after  the after-persist hook (relation syncs — run INSIDE persist,
     *                                              never its own stage, DESIGN §3a)
     * @param  bool  $emit  emit the post-persist {@see BeamParticlePersisted} signal
     */
    public function __construct(
        public Model $model,
        public array $payload,
        public mixed $actor = null,
        public ?Closure $after = null,
        public bool $emit = true,
    ) {}

    /**
     * The record type used to resolve the target schema — a {@see PersistsBeamParticle} model exposes its
     * stem via `recordType()`; any other model has none (validation is skipped).
     */
    public function recordType(): ?string
    {
        return method_exists($this->model, 'recordType') ? $this->model->recordType() : null;
    }

    /**
     * The schema binding the record was written under, for the emitted event — null for a model that carries
     * no binding.
     */
    public function binding(): ?string
    {
        return method_exists($this->model, 'schemaBinding') ? $this->model->schemaBinding() : null;
    }
}
