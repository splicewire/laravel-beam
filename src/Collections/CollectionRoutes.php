<?php

namespace Splicewire\Beam\Collections;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Mount\PendingParticleMount;

/** Hosts mount the one declared resource inside their chosen realm middleware. */
final class CollectionRoutes
{
    public static function mount(string $uri = 'records'): PendingParticleMount
    {
        return Particle::mount('records', $uri)->controller(CollectionController::class);
    }
}
