<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/** Shared central state even while the default database is switched to a tenant. */
class CentralParticleBacking extends WorkspaceSharedParticleBacking
{
    public function ownerFor(Authenticatable $actor): array
    {
        $this->authorizeShared($actor);

        return ['type' => 'central', 'id' => 'host'];
    }

    protected function particle(): BeamParticle
    {
        $tenancy = app(TenancyContext::class);
        $connection = $tenancy->centralConnection();
        if ($tenancy->isActive() && $connection === null) {
            throw new LogicException('Central particle storage requires a configured central connection while a tenant is initialized.');
        }

        return (new BeamParticle)->setConnection($connection);
    }
}
