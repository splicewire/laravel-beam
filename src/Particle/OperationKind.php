<?php

namespace Splicewire\Beam\Particle;

/**
 * The kind of a {@see ParticleOperation} — the axis that decides how it executes.
 *
 *  - **Read**  — a synchronous query/response; the query scope is the gate; never a job.
 *  - **Write** — a synchronous mutation; rides the model policy + emits a domain event; never a job.
 *  - **Task**  — a potentially long-running, side-effectful unit (generate / run / render / reassess).
 *                ONLY this kind is queueable: the framework honours `?async` to `dispatch` vs
 *                `dispatch_sync` its job. (Parallel fan-out is a per-task concern, deliberately NOT
 *                modelled here — a task may fan out internally, the abstraction stays flat.)
 *
 * This three-way split is the load-bearing choice: it keeps read/write operations plain synchronous
 * request/response (no job overhead), and confines the queue/async convention to the operations that
 * genuinely need it — the ones controllers already hand-dispatch jobs for.
 */
enum OperationKind: string
{
    case Read = 'read';
    case Write = 'write';
    case Task = 'task';
}
