<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Http\Particle\SseEmitter;
use Splicewire\Beam\Particle\Emitter;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The `Stream` transport (ADR-0160 §2/§3): a {@see OperationKind::Stream} operation holds the connection
 * and pushes framed events through an {@see Emitter}, with beam-core owning the `StreamedResponse` + SSE
 * headers so no host re-hand-rolls the `event:/data:` + flush plumbing.
 */
class StreamOperationTest extends TestCase
{
    public function test_the_sse_emitter_frames_an_event_as_the_wire_contract(): void
    {
        $frame = (new SseEmitter)->frame('node_status', ['nodeId' => 'a', 'status' => 'complete']);

        $this->assertSame("event: node_status\ndata: {\"nodeId\":\"a\",\"status\":\"complete\"}\n\n", $frame);
    }

    public function test_stream_kind_returns_a_streamed_response_with_sse_headers(): void
    {
        $response = $this->exposedController()->callRunStream(
            $this->streamOp(fn () => null),
            model: (object) [],
            request: Request::create('/'),
        );

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_streaming_pushes_every_frame_the_handle_emits(): void
    {
        $op = $this->streamOp(function ($model, $request, $actor, Emitter $emit) {
            $emit('run_status', ['status' => 'processing']);
            $emit('node_status', ['nodeId' => 'a', 'status' => 'complete']);
        });

        $body = $this->capture(fn () => $this->exposedController()
            ->callRunStream($op, (object) [], Request::create('/'))
            ->sendContent());

        $this->assertStringContainsString("event: run_status\ndata: {\"status\":\"processing\"}\n\n", $body);
        $this->assertStringContainsString("event: node_status\ndata: {\"nodeId\":\"a\",\"status\":\"complete\"}\n\n", $body);
    }

    public function test_the_handle_receives_an_emitter_as_its_fourth_argument(): void
    {
        $seen = null;
        $op = $this->streamOp(function ($model, $request, $actor, $emit) use (&$seen) {
            $seen = $emit;
        });

        $this->capture(fn () => $this->exposedController()
            ->callRunStream($op, (object) [], Request::create('/'))
            ->sendContent());

        $this->assertInstanceOf(Emitter::class, $seen);
    }

    private function streamOp(callable $handle): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'widget',
            name: 'watch',
            kind: OperationKind::Stream,
            model: 'stdClass',
            handle: $handle,
        );
    }

    private function exposedController(): ParticleOperationController
    {
        return $this->app->make(ExposedOperationController::class);
    }

    /** Capture streamed output across the emitter's own `ob_flush` (nest one extra buffer level). */
    private function capture(callable $stream): string
    {
        ob_start();
        ob_start();
        $stream();
        ob_get_clean();

        return (string) ob_get_clean();
    }
}

/** Exposes the protected kind-branch so the transport is testable without a routed request + DB model. */
class ExposedOperationController extends ParticleOperationController
{
    public function callRunStream(ParticleOperation $operation, mixed $model, Request $request): StreamedResponse
    {
        return $this->runStream($operation, $model, $request);
    }
}
