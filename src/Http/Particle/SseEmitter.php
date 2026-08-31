<?php

namespace Splicewire\Beam\Http\Particle;

use Splicewire\Beam\Data\RendersJsonSafely;
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
    use RendersJsonSafely;

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
     * The encode is defended the same way every other beam response body is
     * ({@see RendersJsonSafely}), for a failure mode that is quieter here than anywhere else on the
     * wire: bare `json_encode()` returns `false` rather than throwing, and `false` interpolates to
     * the EMPTY STRING — so an unencodable frame shipped as a well-formed SSE frame with no body.
     * The client's `JSON.parse` failed and the server logged nothing at all. The two-stage shape is
     * the trait's: encode as-is first, so a well-formed frame is byte-identical to what it always
     * was, and substitute a projection only once that has actually failed.
     *
     * @param  array<string, mixed>  $data
     */
    public function frame(string $event, array $data): string
    {
        $json = json_encode($data);

        if (! is_string($json)) {
            $json = json_encode(
                $this->toJsonSafeValue($data),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
        }

        return "event: {$event}\n".'data: '.(is_string($json) ? $json : '{}')."\n\n";
    }
}
