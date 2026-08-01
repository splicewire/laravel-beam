<?php

namespace Splicewire\Beam\Particle;

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
}
