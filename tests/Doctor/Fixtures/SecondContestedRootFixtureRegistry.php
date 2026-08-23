<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * The other half of the collision pair.
 */
#[IsRegistry(
    root: 'fixture.contested',
    of: 'the second claimant of a contested root',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class SecondContestedRootFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
