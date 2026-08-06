<?php

namespace Splicewire\Beam\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * A minimal read Data class so {@see ParticleResource::toResourceDefinition()}
 * has a non-null `data` shape (an admin resource must declare one) — used by the gate-mapping tests.
 */
class WidgetGateData extends Data
{
    public function __construct(
        public int $id = 0,
        public string $name = '',
    ) {}
}
