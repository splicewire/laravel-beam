<?php

namespace Splicewire\Beam\Collections;

use Closure;
use LogicException;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\Stages\PersistStage;
use Splicewire\Beam\Write\WriteContext;

/** Decorate canonical persistence; authorization/validation precede this and emission follows commit. */
final class CollectionPersistStage implements WriteStage
{
    public function __construct(private CollectionSchemaResolver $schemas, private CollectionUniqueWrite $unique) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        if (! $context->model instanceof BeamParticle || $context->recordType() === null) {
            throw new LogicException('Collection persistence requires a schema-bound standalone particle.');
        }
        $definition = $this->schemas->resolve($context->recordType());
        $refs = $this->schemas->recordRefs($context->recordType());
        $context = $this->unique->run($context->model, $definition, $refs, $context->payload,
            fn (): WriteContext => (new PersistStage)->handle($context, fn (WriteContext $written): WriteContext => $written),
        );

        return $next($context);
    }
}
