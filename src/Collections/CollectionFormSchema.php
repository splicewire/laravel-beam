<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Http\Request;
use Schemastud\Frame\Contracts\ResourceSchemaResolver;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** The address selects a tenant schema; ordinary Data resources retain Frame's generator. */
final class CollectionFormSchema implements ResourceSchemaResolver
{
    public function __construct(private ParticleResourceRegistry $resources) {}

    public function schema(ResourceDefinition $resource, Request $request): ?array
    {
        $backing = $this->resources->find($resource->key)?->backing();
        if (! $backing instanceof CollectionBacking) {
            return null;
        }

        return ['type' => 'object', 'required' => ['fields'], 'properties' => [
            'fields' => $backing->definition()->schema,
        ]];
    }
}
