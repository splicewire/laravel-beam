<?php

namespace Splicewire\Beam\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * A minimal read Data class so {@see \Splicewire\Beam\Particle\ParticleAdminResource::toResourceDefinition()}
 * has a non-null `data` shape (an admin resource must declare one) — used by the gate-mapping tests.
 */
class WidgetGateData extends Data
{
    public function __construct(
        public int $id = 0,
        public string $name = '',
    ) {}
}
