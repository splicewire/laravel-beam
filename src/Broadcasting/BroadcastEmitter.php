<?php

namespace Splicewire\Beam\Broadcasting;

use Splicewire\Beam\Particle\Emitter;

/**
 * An {@see Emitter} that fans each frame out over websockets instead of (or, via
 * {@see \Splicewire\Beam\Particle\CompositeEmitter}, alongside) a held SSE connection — the broadcast
 * sink the Phase-1 transport-agnostic Emitter seam was built to accept (ADR-0160). Bind it to a channel;
 * every `($event, $data)` becomes a {@see ParticleFrame} broadcast (inert under the `null` driver).
 */
class BroadcastEmitter implements Emitter
{
    public function __construct(public string $channel) {}

    public function __invoke(string $event, array $data): void
    {
        ParticleFrame::dispatch($this->channel, $event, $data);
    }
}
