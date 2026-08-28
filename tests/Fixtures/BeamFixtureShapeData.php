<?php

namespace Splicewire\Beam\Tests\Fixtures;

use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Fixtures\HasParticleFixtures;

class BeamFixtureShapeData extends BeamData
{
    use HasParticleFixtures;

    public function __construct(public string $name = '') {}

    /** Test seam: `fixtureKey()` is protected, as a tier-override point should be. */
    public static function exposedFixtureKey(): string
    {
        return static::fixtureKey();
    }
}
