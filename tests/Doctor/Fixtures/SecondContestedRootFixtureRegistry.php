<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;

/**
 * The other half of the collision pair.
 */
#[IsRegistry(
    root: 'fixture.contested',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'the second claimant of a contested root',
)]
class SecondContestedRootFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
