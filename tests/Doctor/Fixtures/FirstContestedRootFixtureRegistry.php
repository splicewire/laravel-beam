<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;

/**
 * Half of the collision pair — two registries on one root make that branch unroutable.
 */
#[IsRegistry(
    root: 'fixture.contested',
    onDuplicate: OnDuplicate::Supersede,
    description: 'the first claimant of a contested root',
)]
class FirstContestedRootFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
