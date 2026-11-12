<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleResource;

/** A shared declaration's read door checks its explicit write ability too. */
class SharedParticleAccess implements ResourceReadPolicy
{
    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        if ($actor === null || $resource->policy === null || $resource->policy === '') {
            return Response::deny('Shared particles require an authenticated actor and a declared ability.');
        }

        return Gate::forUser($actor)->inspect($resource->policy, new BeamParticle);
    }
}
