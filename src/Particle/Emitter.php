<?php

namespace Splicewire\Beam\Particle;

/**
 * The sink a {@see OperationKind::Stream} operation pushes framed events into (ADR-0160). Transport-
 * agnostic by design: the HTTP {@see \Splicewire\Beam\Http\Particle\SseEmitter} writes SSE frames today;
 * a broadcast emitter (the Task broadcast facet, ADR-0160 §4) can implement the SAME seam tomorrow, and a
 * test spy can capture frames — the operation's `handle` never knows which transport backs it.
 *
 * It is invokable as `($event, $data)` so a `handle` can hand it straight to any producer that already
 * speaks the `callable(string, array)` progress-callback shape (e.g. `CircuitExecutionService::execute`)
 * without an adapter.
 */
interface Emitter
{
    /**
     * @param  string  $event  the frame's event name (e.g. `node_status`, `run_status`)
     * @param  array<string, mixed>  $data  the frame payload
     */
    public function __invoke(string $event, array $data): void;
}
