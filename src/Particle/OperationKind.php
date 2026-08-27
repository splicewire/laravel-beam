<?php

namespace Splicewire\Beam\Particle;

use Splicewire\Beam\Http\Particle\ParticleOperationController;
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
 *    A `kind: Write` op also does NOT ride {@see ParticleWriter} — its `handle`
 *    closure persists directly, so it skips `AuthorizeStage`'s deny-by-default gate, `ValidateStage`,
 *    `DedupeStage` (so `x-beam-dedupe` can never be honoured on this transport) and `EmitStage`. That is
 *    not an oversight to be swept away: of the 15 write ops at the flagship, **zero** are the "one model,
 *    one payload" shape `ParticleWriter::write()` accepts — they are deletes, multi-row syncs, external
 *    calls and workflow transitions. Requiring the pipeline would refuse all fifteen.
 *
 *    So what `Write` genuinely buys today is **codegen and spec signal**, and that is worth keeping. What
 *    it must not do is read like an enforced invariant. Ticket 02 nominates emitting
 *    `BeamParticlePersisted` as the one stage restorable without constraining `handle` — deliberately NOT
 *    done here, because that event has live listeners (`NotifyOnSubmission`, and the flagship's
 *    `UpdateContextScopeEmbeddingsOnPersist`), so turning it on is an outward-facing behaviour change for
 *    fifteen endpoints, not a docblock fix.
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
