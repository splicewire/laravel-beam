<?php

namespace Splicewire\Beam\Particle;

/**
 * Fans one frame to several {@see Emitter}s — so an operation can push to a held SSE connection AND
 * broadcast to a channel from the SAME `($event, $data)` source (ADR-0160: one frame source, many
 * transports). The composite is itself an {@see Emitter}, so a `handle` passes it wherever one is expected.
 */
class CompositeEmitter implements Emitter
{
    /** @var list<Emitter> */
    private array $emitters;

    public function __construct(Emitter ...$emitters)
    {
        $this->emitters = $emitters;
    }

    public function __invoke(string $event, array $data): void
    {
        foreach ($this->emitters as $emitter) {
            $emitter($event, $data);
        }
    }
}
