<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/** Shared by the trusted workspace, with the same declared ability for reads and writes. */
class WorkspaceSharedParticleBacking extends ActorOwnedParticleBacking
{
    public function forResource(ParticleResource $resource, Request $request): ResourceBacking
    {
        if ($resource->policy === null || $resource->policy === '') {
            throw new LogicException('Shared particle storage requires an explicit write ability.');
        }

        return parent::forResource($resource, $request);
    }

    public function ownerFor(Authenticatable $actor): array
    {
        $this->authorizeShared($actor);
        $tenancy = app(TenancyContext::class);
        $id = $tenancy->currentTenantId();
        if ($tenancy->isActive()) {
            if ($id === null || (string) $id === '') {
                throw new AuthorizationException('Workspace-shared particles require a trusted workspace identity.');
            }

            return ['type' => 'workspace', 'id' => 'tenant:'.$id];
        }
        if ($tenancy->isMultiTenant()) {
            throw new AuthorizationException('Workspace-shared particles require an initialized workspace.');
        }

        return ['type' => 'workspace', 'id' => 'host'];
    }

    protected function authorizeShared(Authenticatable $actor): void
    {
        Gate::forUser($actor)->authorize($this->resource->policy, $this->particle());
    }
}
