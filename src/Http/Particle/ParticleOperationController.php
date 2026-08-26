<?php

namespace Splicewire\Beam\Http\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Particle\Emitter;
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
 *   2. authorize its declared ability (deny-default — the same gate a hand-rolled action used) through
 *      the cross-transport {@see AbilityResolver}, so this transport shares its DECISION with MCP while
 *      keeping its own denial SHAPE (a forbidden status; MCP instead omits the tool from its listing);
 *   3. execute by kind — read/write call `handle` synchronously and return its response; a **task**
 *      builds a `ShouldQueue` job from `handle` and dispatches it **sync or async per `?async`** (the
 *      convention that replaces the copy-pasted `dispatch()`-vs-`dispatchSync()` branch); a **stream**
 *      holds the connection and pushes framed events through an {@see Emitter}
 *      (the SSE `StreamedResponse` plumbing every hand-rolled streaming action copied, ADR-0160 §2).
 *
 * The operation stays the host's: `handle` is their code (often literally a former controller method).
 *
 * The response the task's `respond` projector doesn't cover is built through the {@see ResponseEnvelope}
 * seam (ADR-0116), so beam-core owes nothing to the host's response DTO.
 */
class ParticleOperationController extends Controller
{
    /** Route-default keys naming the operation. */
    public const RESOURCE = '_particle_op_resource';

    public const NAME = '_particle_op_name';

    /**
     * The framework's own parameter on a {@see OperationKind::Task}: run the job queued (default) or inline.
     * Spelled here once and read everywhere through {@see OperationKind::frameworkParameters()}, so the
     * dispatch branch, the published reference and the input-less rejection cannot disagree about its name.
     */
    public const ASYNC = 'async';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected Dispatcher $bus,
        protected ResponseEnvelope $envelope,
        protected AbilityResolver $abilities,
        protected ActorPort $actor,
    ) {}

    public function invoke(Request $request, string $id): mixed
    {
        $defaults = $request->route()?->defaults ?? [];
        $resource = $defaults[static::RESOURCE] ?? throw new RuntimeException('Operation route missing its resource default.');
        $name = $defaults[static::NAME] ?? throw new RuntimeException('Operation route missing its name default.');

        $operation = $this->operations->get($resource, $name);
        $model = $operation->model::query()->findOrFail($id);

        // A validly-signed request is itself a credential (api-surface-coherence ticket 95). Checked
        // HERE and not in {@see AbilityResolver} on purpose: the resolver takes an actor argument and
        // deliberately never reads ambient authentication, because MCP over stdio has no request to
        // read a signature from. So the signature plane is the TRANSPORT's, alongside the denial shape
        // the transport already owns — and MCP, having no signature plane, falls through to `ability:`.
        //
        // Sufficient, not required: it satisfies the ability, and an op that must ONLY be reachable
        // signed says so with Laravel's `signed` middleware on the mount, which refuses earlier than
        // this. Ordered before the ability so an ANONYMOUS signed-link holder is admitted rather than
        // measured against a policy that can only ever see an actor — the exact 403 that made
        // `beam-accounts`' `LogInAsUser` hand-roll its gate.
        $admittedBySignature = $operation->signed && $request->hasValidSignature();

        if (! $admittedBySignature && is_string($operation->ability)) {
            // Deny-default: the ability is checked against its declared subject.
            //
            // `abilityModel` chooses the PLANE, three-state (particle-operation-surface ticket 03):
            // `null` ⇒ the resolved instance; a class-string ⇒ a cross-model subject (run authorizes
            // `create` on Fragment); `false` ⇒ NO subject, which routes {@see AbilityResolver} to its
            // subject-free entitlement plane. An entitlement key checked WITH a subject silently
            // becomes a policy verb, which is why the third state exists.
            //
            // The DECISION goes through the shared {@see AbilityResolver} so HTTP and MCP cannot answer
            // "may this actor invoke this?" differently; the DENIAL SHAPE stays this transport's, which is
            // why the forbidden response is constructed here and not in the resolver. The actor comes from
            // the {@see ActorPort} rather than `$request->user()` for the same reason — the resolver must
            // be asked the identical question by a transport that has no ambient user.
            $subject = $operation->abilityModel === false
                ? null
                : ($operation->abilityModel ?? $model);

            if ($this->abilities->denies($this->actor->actor(), $operation->ability, $subject)) {
                throw new AuthorizationException;
            }
        }

        // `false` is DECLARED-ungated and `null` is UNDECLARED, and both fall through here today. They
        // are not the same fact and must not stay spelled the same: `false` is a reviewed decision
        // (`StopImpersonating` — an ability there locks the operator inside the impersonated session),
        // `null` is the residue {@see ParticleOperation::permissionName()} is scheduled to close.
        // {@see \Splicewire\Beam\Doctor\UngatedOperationAudit} is what keeps the residue counted.

        $this->validateInput($operation, $request);

        return match ($operation->kind) {
            OperationKind::Task => $this->runTask($operation, $model, $request),
            OperationKind::Stream => $this->runStream($operation, $model, $request),
            default => $this->finish(
                $operation,
                ($operation->handle)($model, $request, $request->user()),
                $model,
            ),
        };
    }

    /**
     * Enforce the operation's declared `input:` before the handler runs.
     *
     * The declaration becomes the contract rather than staying implicit in the handler body, across all
     * three of its states (api-surface-coherence ticket 30):
     *
     *   - a class-string validates the payload;
     *   - `false` REJECTS one — an op that declares it accepts nothing must not silently ignore what it is
     *     sent, or "accepts nothing" is prose rather than a contract;
     *   - `null` is undeclared and stays untouched, which is the state every operation is in until the
     *     declaration sweep reaches it. {@see ParticleOperation} carries the schedule for closing it.
     */
    protected function validateInput(ParticleOperation $operation, Request $request): void
    {
        $input = $operation->input;

        if ($input === false) {
            $this->rejectInput($operation, $request);

            return;
        }

        if ($input !== null && is_subclass_of($input, Data::class)) {
            $input::validate($request->all());
        }
    }

    /**
     * Refuse a request that carries input to an operation declared to accept none.
     *
     * Only the op's OWN axis is examined, and which axis that is belongs to the mount rather than the
     * declaration: `Route::particleOp()` chooses the HTTP method, so a GET op's input arrives as a query
     * string and every other op's as a body. Reading both would make `?async` — which is beam's parameter,
     * not the caller's payload — look like a violation on the very kind that defines it.
     *
     * What is forgiven comes from {@see ParticleOperation::frameworkParameters()} rather than from the
     * KIND, because the kind cannot see the declaration: a `signed:` op receives `expires`/`signature`
     * from Laravel's URL signer and no host `input:` could ever declare them, so asking the enum alone
     * read them as unexpected caller input and 422'd every signed link (ticket 95).
     */
    protected function rejectInput(ParticleOperation $operation, Request $request): void
    {
        $source = match (true) {
            $request->isMethod('GET') => $request->query(),
            // A JSON body never reaches the `request` bag, so `post()` alone would read every JSON payload
            // as empty and this contract would enforce nothing on the format the API actually speaks.
            $request->isJson() => (array) $request->json()->all(),
            default => $request->post(),
        };

        $unexpected = array_diff(array_keys($source), $operation->frameworkParameters());

        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(
            array_values($unexpected),
            "The `{$operation->name}` operation accepts no input.",
        ));
    }

    /**
     * Turn a handler's return value into the response — the seam that lets an operation's business logic
     * return a PAYLOAD while response construction stays a separate concern, so the same logic can serve more
     * than one transport.
     *
     * Order is load-bearing:
     *
     *   1. A declared payload Data object is enveloped. This must come FIRST because a spatie Data object is
     *      itself `Responsable`, so the pass-through check below would otherwise swallow every declared
     *      payload and this whole ticket would be a no-op.
     *   2. An already-built response passes through UNTOUCHED — the escape hatch, and it is load-bearing
     *      rather than defensive. Three live operations need it and each names a distinct reason: a binary
     *      download whose pass-through is a stated contract (flattening it into a JSON envelope would corrupt
     *      it), two operations returning a redirect alongside session mutation, and one carrying a specific
     *      accepted status code that a declared payload slot has no channel to express.
     *   3. Anything else — an array, null, a scalar — is returned as-is, which is exactly what every
     *      pre-existing read/write handler already did. That keeps this change additive: handlers already
     *      returning `['data' => …]` envelopes are not double-wrapped.
     *
     * `respond` is consulted for EVERY kind, not just the queued one, and receives the payload first and the
     * model second. Its own return runs back through the same rules, so a projector may hand back a payload
     * or a full response.
     */
    protected function finish(ParticleOperation $operation, mixed $payload, mixed $model): mixed
    {
        if ($operation->respond !== null) {
            $payload = ($operation->respond)($payload, $model);
        }

        if ($payload instanceof Data) {
            return $this->envelope->item($payload);
        }

        return $payload;
    }

    /**
     * Hold the connection and stream the operation's frames. The host's `handle` receives an
     * {@see Emitter} as its 4th arg (after `$model, $request, $actor`) and pushes
     * `($event, $data)` frames; beam-core owns the `StreamedResponse` + SSE headers, so the plumbing every
     * hand-rolled streaming action copied (CircuitController::run/resume) lives here once (ADR-0160 §2/§3).
     */
    protected function runStream(ParticleOperation $operation, mixed $model, Request $request): mixed
    {
        $stream = SseEmitter::stream(
            fn ($emit) => ($operation->handle)($model, $request, $request->user(), $emit),
        );

        // Consulted here too, so `respond` means the same thing on all four kinds. A stream's payload IS its
        // StreamedResponse — beam-core owns the SSE plumbing — so a projector can decorate it (extra headers,
        // a wrapper) but returning it untouched is the norm, and the default (no `respond`) is unchanged.
        return $this->finish($operation, $stream, $model);
    }

    /**
     * Dispatch a task's job sync or async per `?async` (default async). The SAME operation runs either way
     * with no host branching — the host's `handle` just returns the job.
     */
    protected function runTask(ParticleOperation $operation, mixed $model, Request $request): mixed
    {
        $job = ($operation->handle)($model, $request, $request->user());
        $async = $request->boolean(static::ASYNC, true);

        if ($async) {
            $this->bus->dispatch($job);
        } else {
            set_min_time_limit(6 * 6000);
            $this->bus->dispatchSync($job);
        }

        $model->refresh();

        // A task's handler returns the JOB, not a payload, so the payload here is the dispatch OUTCOME. That
        // is what the default envelope has always reported, and a `respond` projector still gets the refreshed
        // model as its second argument — which is what both live projectors actually read.
        if ($operation->respond !== null) {
            return $this->finish($operation, ['queued' => $async], $model);
        }

        return $this->envelope->item(['queued' => $async]);
    }
}
