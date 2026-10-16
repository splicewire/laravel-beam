<?php

namespace Splicewire\Beam\Webhooks;

use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;

/** Hooks keep the shared read/update lifecycle and declare a reveal-once create result. */
class HookFrameResourceHandler extends ParticleFrameResourceHandler
{
    public function store(ResourceDefinition $definition, array $input): array
    {
        $this->assertWritable($definition, 'store');

        return app(CreateHookSubscription::class)->create(
            HookInputData::validateAndCreate($input),
            $this->contextRequest($this->resource($definition)),
        )->toArray();
    }
}
