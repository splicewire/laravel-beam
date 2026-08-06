<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\PayloadRejected;
use Splicewire\Beam\Write\WriteContext;

/**
 * Stage 2 of the write chain (DESIGN §3a): validate the payload against the record type's resolved target
 * schema via the {@see AcceptanceGate}. A record type with no registered schema resolves an empty target — a
 * total, non-throwing "no target" — so app models riding the pipeline validate at their own DTO boundary,
 * not here (the writer stays genuinely model-agnostic).
 *
 * @throws PayloadRejected the payload does not conform to its target schema (nothing persisted)
 */
class ValidateStage implements WriteStage
{
    public function __construct(
        private SchemaTargetResolver $targets,
        private AcceptanceGate $acceptance,
    ) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $recordType = $context->recordType();
        if ($recordType !== null) {
            $targetSchema = $this->targets->targetFor($recordType);
            if ($targetSchema !== [] && ! $this->acceptance->accepts($context->payload, $targetSchema)) {
                throw PayloadRejected::for($recordType);
            }
        }

        return $next($context);
    }
}
