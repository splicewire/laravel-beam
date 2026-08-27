<?php

namespace Splicewire\Beam\Particle;

use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The kind of a {@see ParticleOperation} — the axis that decides how it executes AND how it delivers
 * (ADR-0160: the *transport axis* — Unary vs Queued vs Streamed — folded onto this enum rather than a
 * separate semantics×transport matrix).
 *
 *  - **Read**   — a synchronous query/response; the query scope is the gate; never a job. (Unary)
 *  - **Write**  — a synchronous mutation; never a job. (Unary)
 *
 *    ⚠️ **`Write` is DECLARATIVE ONLY. It changes nothing at runtime, and this line used to claim
 *    otherwise.** It read *"rides the model policy + emits a domain event"* — both halves refuted by a
 *    gate-closed probe on 2026-08-27 (particle-write-surface ticket 02). `ParticleOperationController`
 *    branches on kind at `invoke()`, and `Read` and `Write` share the `default =>` arm: no HTTP-verb
 *    constraint, no policy ride, no transaction, no event. `BeamParticlePersisted` appears in 39 files
 *    estate-wide and **none of them is one of the 15 registered write operations**; nothing emits it by
 *    hand either.
 *
 *    A `kind: Write` op also does NOT ride {@see ParticleWriter} — its `handle` closure persists
 *    directly, so it skips `AuthorizeStage`'s deny-by-default gate, `ValidateStage`, `DedupeStage` (so
 *    `x-beam-dedupe` can never be honoured on this transport) and `EmitStage`.
 *
 *    ⚠️ **This entry briefly argued that requiring the pipeline was impossible, and that was wrong.** It
 *    cited "zero of the 15 write ops are the one-model-one-payload shape `ParticleWriter::write()`
 *    accepts". True, and irrelevant, because it conflates two separable things:
 *
 *      - **the payload-persist pipeline** (validate → dedupe → persist → emit), which genuinely only fits
 *        an attribute write — a delete or a workflow transition has nothing to persist;
 *      - **the deny-by-default write GATE**, which needs no payload at all, only a subject. And there is
 *        almost always a primary model: `ParticleWriter::write()` already takes `Model|string $target`,
 *        and every one of the 15 resolves a subject before `handle` runs.
 *
 *    Measuring the first and concluding the second is impossible is how a signature became mistaken for a
 *    constraint. The gate is the half that matters and the half that is missing: a gate-closed probe found
 *    **four** write ops reachable by any authenticated, role-less tenant member (install/uninstall/refresh
 *    extensions, spend embedding budget). So `Write` SHOULD mean "gated", and today does not.
 *
 *    What it buys meanwhile is codegen and spec signal. The two known routes to making it mean something
 *    are recorded rather than chosen here: an `ability:` on each op (see particle-write-surface ticket 02
 *    — measured, costed, and blocked on a dataset that can discriminate), and deriving the mounted verb
 *    set from the backing's CAPABILITY so a resource can declare write-only and the operations fall out
 *    ({@see WritesRecords}; the seam exists, `hasCapability()` is
 *    consulted nowhere at runtime, and particle-operation-surface ticket 04 designed exactly this and
 *    deferred the build).
 *
 *    Emitting `BeamParticlePersisted` is deliberately NOT done here either: that event has live listeners
 *    (`NotifyOnSubmission`, and the flagship's `UpdateContextScopeEmbeddingsOnPersist`), so turning it on
 *    is an outward-facing behaviour change for fifteen endpoints, not a docblock fix.
 *  - **Task**   — a potentially long-running, side-effectful unit (generate / run / render / reassess).
 *                 ONLY this kind is queueable: the framework honours `?async` to `dispatch` vs
 *                 `dispatch_sync` its job. (Queued. Parallel fan-out is a per-task concern, deliberately
 *                 NOT modelled here — a task may fan out internally, the abstraction stays flat.)
 *  - **Stream** — a synchronous, connection-held push: the handle receives an {@see Emitter} (4th arg,
 *                 after `$model, $request, $actor`) and pushes framed events until it returns; the
 *                 framework supplies the `StreamedResponse` + SSE headers so the copy-pasted
 *                 `event:/data:` + `ob_flush`/`flush` plumbing lives in ONE place. Like Read/Write it is
 *                 never queued; unlike them it emits many frames over one held request, not one body.
 *                 (Streamed. Websocket fan-out is the Task *broadcast facet*, ADR-0160 §4 — a different
 *                 transport, not a kind.)
 *
 * This split keeps read/write operations plain synchronous request/response (no job overhead), confines
 * the queue/async convention to the operations that genuinely need it, and gives streaming actions a home
 * in the registry instead of a hand-rolled `StreamedResponse` controller (the ADR-0152 tier-D residue).
 */
enum OperationKind: string
{
    case Read = 'read';
    case Write = 'write';
    case Task = 'task';
    case Stream = 'stream';

    /**
     * The parameters the FRAMEWORK accepts on an operation of this kind, as opposed to the ones the host
     * declares through `input:`.
     *
     * A Task honours `?async`, and that flag is beam's rather than the host's — no `input:` a host writes
     * could ever declare it, which is why it needs a home of its own. Naming it here, once, is what lets
     * {@see ParticleOperationController::runTask()} read it, the reference publish it, and a
     * deliberately-input-less op exclude it from what it rejects, without any of the three spelling the
     * word (api-surface-coherence ticket 30; the `ParticleController::PER_PAGE` precedent from ticket 21).
     *
     * A Stream's `text/event-stream` transport is deliberately absent: it is a RESPONSE fact, already
     * reaching the spec through the streams declaration, and there is no query parameter behind it.
     *
     * ⚠️ This is the KIND's contribution, not the whole set. An operation's full framework-parameter
     * list is {@see ParticleOperation::frameworkParameters()}, which unions this with the URL signer's
     * `expires`/`signature` on a `signed:` op — a fact of the DECLARATION that an enum case cannot see.
     * Call the operation's, never this one, anywhere the answer is "what may a caller send" (ticket 95).
     *
     * @return list<string>
     */
    public function frameworkParameters(): array
    {
        return match ($this) {
            self::Task => [ParticleOperationController::ASYNC],
            default => [],
        };
    }
}
