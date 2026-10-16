<?php

namespace Splicewire\Beam\Particle\Backing;

use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/** Optional persistence composition; the transport's gate must remain the first stage. */
interface SuppliesParticleWriter extends ResourceBacking
{
    public function writer(WriteGate $gate): ParticleWriter;
}
