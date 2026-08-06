<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteNotAuthorized;

/**
 * Stage 1 of the write chain (DESIGN §3a): authorize through the deny-by-default {@see WriteGate}. A record
 * type nothing explicitly permits is refused HERE — before any validation or write — so nothing downstream
 * (validate, persist, emit) ever runs for an unauthorised write.
 *
 * @throws WriteNotAuthorized the gate refused the write (nothing persisted)
 */
class AuthorizeStage implements WriteStage
{
    public function __construct(private WriteGate $gate) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        if (! $this->gate->authorizes($context->model, $context->payload, $context->actor)) {
            throw WriteNotAuthorized::for($context->model);
        }

        return $next($context);
    }
}
