<?php

namespace Splicewire\Beam\Tests\Fixtures;

use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Particle\Fixtures\HasParticleFixtures;

class BeamFixtureShapeData extends Data
{
    use HasParticleFixtures;

    public function __construct(public string $name = '') {}

    /** Test seam: `fixtureKey()` is protected, as a tier-override point should be. */
    public static function exposedFixtureKey(): string
    {
        return static::fixtureKey();
    }
}
