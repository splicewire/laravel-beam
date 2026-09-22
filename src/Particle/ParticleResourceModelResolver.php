<?php

namespace Splicewire\Beam\Particle;

use Rushing\DataFilters\Contracts\ResourceModelResolver;
use Schemastud\Frame\Contracts\ResourceRegistry;

/** Resolves the current backing model without caching request-dependent backing state. */
class ParticleResourceModelResolver implements ResourceModelResolver
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * @return class-string|null
     */
    public function resolveModel(string $resourceKey): ?string
    {
        $particle = $this->registry->find($resourceKey);
        if ($particle !== null) {
            return $particle->modelClass();
        }

        return app()->bound(ResourceRegistry::class)
            ? app(ResourceRegistry::class)->find($resourceKey)?->model
            : null;
    }
}
