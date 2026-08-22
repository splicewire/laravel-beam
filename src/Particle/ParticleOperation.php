<?php

namespace Splicewire\Beam\Particle;

use Closure;
use InvalidArgumentException;
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
 *
 * `input`/`output` are the op's DECLARED SHAPES — the invariant's second legal declaration site, mirroring the
 * `input`/`data` pair a {@see ParticleResource} already carries. Both optional, so an op that declares neither
 * behaves exactly as it did before the slots existed.
 *
 * `input` is THREE-STATE, and the third state is what makes the second auditable (api-surface-coherence
 * ticket 30):
 *
 *   - a **class-string** — the op accepts this payload, validated before `handle` runs and published as the
 *     endpoint's contract;
 *   - **`false`** — the op accepts NOTHING, deliberately. Enforced: a request carrying input on the op's
 *     axis is rejected rather than silently ignored;
 *   - **`null`** — UNDECLARED, which today means "accept anything, validate nothing". This is the residue,
 *     not a design: it is the state an op is in because nobody has looked at it yet.
 *
 * The axis `input` describes is the ROUTE's, not the declaration's: `Route::particleOp()` chooses the HTTP
 * method, so the same declared class publishes as a request body on a write op and as query parameters on a
 * GET one. A declaration says WHAT is accepted; the mount says where it arrives.
 *
 * **`null` is scheduled to become a synonym for `false`** — a contract that is only binding when present is
 * not a contract. That flip is deliberately NOT made here: at the time of writing, zero of the estate's
 * registered operations declare `input` at all, so making it binding today would break every one of them.
 * The gate is the count of remaining `null`s reaching zero across the registry, and the flip covers the
 * resource axis ({@see ParticleResource::$input}) in the same act.
 *
 * `output` is kind-dependent, and that asymmetry is deliberate rather than an inconsistency: a read/write/task
 * resolves ONE payload, so a single class-string says everything; a Stream emits a sequence of discrete typed
 * events under distinct wire names, so it takes `[eventName => [DataClass, ...]]` — the shape the `->streams()`
 * route macro already proved. One event name may cover several payload variants discriminated by a DTO field,
 * so the value is a LIST, not a single class. Collapsing a stream to one class would lose the event-name
 * dimension a client needs to narrow each listener.
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
     * @param  class-string|false|null  $input  the Data class this op ACCEPTS — its declared payload
     *                                          contract; `false` declares it accepts nothing; `null` is
     *                                          undeclared (see the class docblock)
     * @param  class-string|array<string, list<class-string>>|null  $output  the Data class this op RETURNS; on
     *                                                                       a Stream, an event-name → payload-list
     *                                                                       map (see the class docblock)
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
        public string|false|null $input = null,
        public string|array|null $output = null,
    ) {
        $this->assertOutputMatchesKind();
    }

    /**
     * A Stream's `output` is an event-name map and every other kind's is a single class — the two are not
     * interchangeable, so a mismatch is a declaration bug and fails at registration rather than silently
     * generating the wrong client type.
     *
     * This runs in the constructor deliberately: attribute discovery and manual `register()` both build this
     * object, so validating here is the one place that catches both paths.
     */
    private function assertOutputMatchesKind(): void
    {
        if ($this->output === null) {
            return;
        }

        $isStream = $this->kind === OperationKind::Stream;

        if ($isStream && ! is_array($this->output)) {
            throw new InvalidArgumentException(
                "Particle operation [{$this->key()}] is a Stream, so its `output:` must be an event-name map "
                .'of `[eventName => [DataClass, ...]]` — a stream emits discrete typed events under distinct '
                .'wire names, not one resolved payload.'
            );
        }

        if (! $isStream && is_array($this->output)) {
            throw new InvalidArgumentException(
                "Particle operation [{$this->key()}] is a {$this->kind->name}, so its `output:` must be a "
                .'single Data class-string. An event-name map is only meaningful on a Stream.'
            );
        }
    }

    public function key(): string
    {
        return "{$this->resource}:{$this->name}";
    }
}
