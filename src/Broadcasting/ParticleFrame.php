<?php

namespace Splicewire\Beam\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One operation frame fanned out over websockets (ADR-0160 §4) — the broadcast dual of an SSE frame. A
 * Stream/Task op's `($event, $data)` pushed to a channel so watchers beyond the initiating request (who
 * hold no SSE connection) can follow it: the fan-OUT transport to SSE's fan-to-one.
 *
 * Inert until a real broadcaster is configured (`broadcasting.default !== 'null'`), so wiring a broadcast
 * sink is a no-op until Reverb/Pusher is stood up — it carries no config flag of its own.
 */
class ParticleFrame implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $channel,
        public string $event,
        public array $data,
    ) {}

    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null';
    }

    public function broadcastOn(): Channel
    {
        return new Channel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'particle.frame';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['event' => $this->event, 'data' => $this->data];
    }
}
