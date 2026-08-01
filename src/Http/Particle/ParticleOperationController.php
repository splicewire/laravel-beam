<?php

namespace Splicewire\Beam\Http\Particle;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs a declared {@see ParticleOperation} mounted by `Route::particleOp()` at
 * `POST /{resource}/{id}/op/{name}`. It supplies the cross-cutting plumbing so the host's `handle`
 * closure stays ordinary code:
 *
 *   1. resolve the operation (from the route defaults) + the `{id}` model;
 *   2. authorize its declared ability (deny-default — the same gate a hand-rolled action used);
 *   3. execute by kind — read/write call `handle` synchronously and return its response; a **task**
 *      builds a `ShouldQueue` job from `handle` and dispatches it **sync or async per `?async`** (the
 *      convention that replaces the copy-pasted `dispatch()`-vs-`dispatchSync()` branch); a **stream**
 *      holds the connection and pushes framed events through an {@see \Splicewire\Beam\Particle\Emitter}
 *      (the SSE `StreamedResponse` plumbing every hand-rolled streaming action copied, ADR-0160 §2).
 *
 * The operation stays the host's: `handle` is their code (often literally a former controller method).
 *
 * The response the task's `respond` projector doesn't cover is built through the {@see ResponseEnvelope}
 * seam (ADR-0116), so beam-core owes nothing to the host's response DTO.
 */
class ParticleOperationController extends Controller
{
    use AuthorizesRequests;

    /** Route-default keys naming the operation. */
    public const RESOURCE = '_particle_op_resource';

    public const NAME = '_particle_op_name';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected Dispatcher $bus,
        protected ResponseEnvelope $envelope,
    ) {}

    public function invoke(Request $request, string $id): mixed
    {
        $defaults = $request->route()?->defaults ?? [];
        $resource = $defaults[static::RESOURCE] ?? throw new RuntimeException('Operation route missing its resource default.');
        $name = $defaults[static::NAME] ?? throw new RuntimeException('Operation route missing its name default.');

        $operation = $this->operations->get($resource, $name);
        $model = $operation->model::query()->findOrFail($id);

        if ($operation->ability !== null) {
            // Deny-default: the ability is checked against its declared model (a cross-model op names its
            // own — run authorizes `create` on Fragment), else the resolved instance.
            $this->authorize($operation->ability, $operation->abilityModel ?? $model);
        }

        return match ($operation->kind) {
            OperationKind::Task => $this->runTask($operation, $model, $request),
            OperationKind::Stream => $this->runStream($operation, $model, $request),
            default => ($operation->handle)($model, $request, $request->user()),
        };
    }

    /**
     * Hold the connection and stream the operation's frames. The host's `handle` receives an
     * {@see \Splicewire\Beam\Particle\Emitter} as its 4th arg (after `$model, $request, $actor`) and pushes
     * `($event, $data)` frames; beam-core owns the `StreamedResponse` + SSE headers, so the plumbing every
     * hand-rolled streaming action copied (CircuitController::run/resume) lives here once (ADR-0160 §2/§3).
     */
    protected function runStream(ParticleOperation $operation, mixed $model, Request $request): StreamedResponse
    {
        return SseEmitter::stream(
            fn ($emit) => ($operation->handle)($model, $request, $request->user(), $emit),
        );
    }

    /**
     * Dispatch a task's job sync or async per `?async` (default async). The SAME operation runs either way
     * with no host branching — the host's `handle` just returns the job.
     */
    protected function runTask(ParticleOperation $operation, mixed $model, Request $request): mixed
    {
        $job = ($operation->handle)($model, $request, $request->user());
        $async = $request->boolean('async', true);

        if ($async) {
            $this->bus->dispatch($job);
        } else {
            set_min_time_limit(6 * 6000);
            $this->bus->dispatchSync($job);
        }

        $model->refresh();

        return $operation->respond !== null
            ? ($operation->respond)($model)
            : $this->envelope->item(['queued' => $async]);
    }
}
