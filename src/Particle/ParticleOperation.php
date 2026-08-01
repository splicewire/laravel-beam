<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * A named operation on a particle resource, mounted at `POST /{resource}/{id}/op/{name}` by
 * `Route::particleOp()` and run by {@see ParticleOperationController}.
 *
 * This generalizes the CRUD verbs to arbitrary named actions — the escape hatch that lets the bespoke
 * (bucket-D) controllers dissolve their *actions* while the framework supplies the cross-cutting plumbing
 * (routing, params validation, authorization, and — for a {@see OperationKind::Task} — the sync/async
 * convention). The `handle` closure is HOST-WRITTEN and may do anything a controller method does (it IS,
 * in effect, a controller method that the framework calls) — so a Laravel dev keeps writing ordinary code
 * and simply returns a job for a Task or a response envelope for a read/write.
 *
 * The {@see OperationKind} is what avoids over-engineering: read/write ops are plain sync calls; only a
 * Task rides the queue. A Task's `handle` returns a `ShouldQueue` job (the framework dispatches it sync or
 * async per `?async`) rather than doing the work inline, so the SAME operation can run either way with no
 * host branching — the `?async` dance controllers copy today (FragmentUrlBatch.run, Composition.*)
 * collapses to a convention. A Stream's `handle` instead receives an {@see Emitter} 4th arg and pushes
 * framed events down a held connection; the framework owns the `StreamedResponse` (ADR-0160).
 */
class ParticleOperation
{
    /**
     * @param  string  $resource  the particle resource key this operation hangs off (for the route + auth)
     * @param  string  $name  the operation slug in the URL (`…/op/{name}`)
     * @param  OperationKind  $kind  read | write | task | stream — sync-call vs queueable-dispatch vs held-stream
     * @param  class-string  $model  the model the `{id}` resolves to
     * @param  Closure  $handle  host code. Task ⇒ returns a `ShouldQueue` job built from
     *                           `($model, $request, $actor)`; Read/Write ⇒ returns a response envelope;
     *                           Stream ⇒ `($model, $request, $actor, Emitter $emit)`, pushes framed events.
     * @param  string|null  $ability  an authorization ability checked before the op runs (deny-default)
     * @param  class-string|null  $abilityModel  the model the ability is checked against; null ⇒ the
     *                                           resolved instance (a cross-model ability names its own,
     *                                           e.g. run authorizes `create` on `Fragment`, not the batch)
     * @param  (Closure(mixed): mixed)|null  $respond  a Task's response projector
     *                                                 (given the refreshed model); null ⇒ a bare `{ queued: true|false }`
     */
    public function __construct(
        public string $resource,
        public string $name,
        public OperationKind $kind,
        public string $model,
        public Closure $handle,
        public ?string $ability = null,
        public ?string $abilityModel = null,
        public ?Closure $respond = null,
    ) {}

    public function key(): string
    {
        return "{$this->resource}:{$this->name}";
    }
}
