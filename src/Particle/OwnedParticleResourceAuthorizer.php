<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Authorization\ResourceAuthorizer;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Backing\UsesActorOwnership;

/** Per-declaration authorization for a shared model; other resources keep Frame's model policy. */
class OwnedParticleResourceAuthorizer extends ResourceAuthorizer
{
    public function allows(ResourceDefinition $definition, string $ability, ?string $id = null): bool
    {
        $resource = app(ParticleResourceRegistry::class)->find($definition->key);
        $backing = $resource?->backing(request());
        if (! $backing instanceof UsesActorOwnership) {
            return parent::allows($definition, $ability, $id);
        }
        if ($resource->policy === null) {
            return false;
        }
        if ($id !== null) {
            \Splicewire\Beam\Routing\RecordKey::assertValidFor($backing->modelClass(), $id);
        }
        $subject = $id === null ? $backing->newRecord()->model() : $backing->query([])->find($id);
        // The handler owns the scoped 404, as for Frame's ordinary scoped subject resolver.
        $subject ??= $backing->newRecord()->model();

        return $this->gate->allows($resource->policy, $subject);
    }

    public function recordCapabilities(ResourceDefinition $definition, Model $record): array
    {
        $resource = app(ParticleResourceRegistry::class)->find($definition->key);
        if (! $resource?->backing(request()) instanceof UsesActorOwnership) {
            return parent::recordCapabilities($definition, $record);
        }
        $allowed = $this->access->allowsResource($definition) && $resource->policy !== null
            && $this->gate->allows($resource->policy, $record);

        return ['create' => $definition->creatable && $allowed, 'update' => $definition->editable && $allowed,
            'delete' => $definition->deletable && $allowed];
    }
}
