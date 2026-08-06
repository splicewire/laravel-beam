<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\WriteContext;

/**
 * Stage 4 (terminal) of the write chain (DESIGN §3a): emit the single {@see BeamParticlePersisted} signal
 * every write path shares — UNLESS the caller suppressed it. A LIGHT shadow-write (the sourced-particles
 * cache tier, ticket 06) sets `emit = false` to defer the adoption side-effects the event drives (embedding
 * jobs, indexing, notification); the full chain — including this signal — fires ONCE later on `promote()`.
 */
class EmitStage implements WriteStage
{
    public function __construct(private Dispatcher $events) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        if ($context->emit) {
            $this->events->dispatch(
                new BeamParticlePersisted($context->model, $context->payload, $context->binding()),
            );
        }

        return $next($context);
    }
}
