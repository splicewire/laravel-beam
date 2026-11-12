<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\ParticleResource;

/** Request-local declaration binding; a Frame caller passes its own actor-bound request. */
interface BindsResource extends ResourceBacking
{
    public function forResource(ParticleResource $resource, Request $request): ResourceBacking;
}
