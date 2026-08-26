<?php

namespace Splicewire\Beam\Particle;

use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * The kind of a {@see ParticleOperation} — the axis that decides how it executes AND how it delivers
 * (ADR-0160: the *transport axis* — Unary vs Queued vs Streamed — folded onto this enum rather than a
 * separate semantics×transport matrix).
 *
 *  - **Read**   — a synchronous query/response; the query scope is the gate; never a job. (Unary)
 *  - **Write**  — a synchronous mutation; rides the model policy + emits a domain event; never a job. (Unary)
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
