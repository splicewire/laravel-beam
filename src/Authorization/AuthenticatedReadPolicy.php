<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\ParticleResource;

/** Declares that any authenticated actor may read through the resource's separate row boundary. */
class AuthenticatedReadPolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        return $actor !== null
            ? Response::allow()
            : Response::deny("Reading [{$resource->key}] requires an authenticated actor.");
    }
}
