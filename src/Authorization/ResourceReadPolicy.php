<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\ParticleResource;

/** A resource-owned primary read decision, used instead of its backing model's viewAny policy. */
interface ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response;
}
