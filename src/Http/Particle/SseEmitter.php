<?php

namespace Splicewire\Beam\Http\Particle;

use Splicewire\Beam\Particle\Emitter;
use Splicewire\Beam\Particle\OperationKind;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SSE-backed {@see Emitter}: writes one `text/event-stream` frame per call and flushes it down the
 * held response. This is the copy-pasted `echo "event: …" / "data: …" / ob_flush / flush` block — the one
 * every hand-rolled `StreamedResponse` duplicated (CircuitController::run/resume) — extracted to ONE place
 * so a {@see OperationKind::Stream} operation's `handle` just pushes frames
 * (ADR-0160 §2/§3).
 *
 * {@see self::stream()} is the matching `StreamedResponse` factory — it owns the SSE headers so the
 * generic `Stream` op path ({@see ParticleOperationController::runStream()}) AND any surface that can't
 * fold onto the op grammar (the nested-subject `CircuitController::resume`) share ONE plumbing seam.
 */
class SseEmitter implements Emitter
{
    /**
     * Build a `text/event-stream` `StreamedResponse` whose body is produced by `$producer`, handed a fresh
     * SSE {@see Emitter} bound to the open connection. The one home for the SSE headers + response wrapper.
     *
     * @param  callable(Emitter): void  $producer
     */
    public static function stream(callable $producer): StreamedResponse
    {
        return new StreamedResponse(function () use ($producer) {
            $producer(new self);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function __invoke(string $event, array $data): void
    {
        echo $this->frame($event, $data);

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * The one `text/event-stream` frame for an event — the wire contract, kept pure (and testable) apart
     * from the flush that pushes it down the connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function frame(string $event, array $data): string
    {
        return "event: {$event}\n".'data: '.json_encode($data)."\n\n";
    }
}
