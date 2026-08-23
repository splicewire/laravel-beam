<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Half of the collision pair — two registries on one root make that branch unroutable.
 */
#[IsRegistry(
    root: 'fixture.contested',
    of: 'the first claimant of a contested root',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class FirstContestedRootFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
