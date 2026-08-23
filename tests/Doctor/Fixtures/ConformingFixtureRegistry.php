<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Complete: implements the contract and writes every slot the gate asks for. The control case — without
 * one, a test asserting that a defect FIRES cannot tell a working check from a check that fires on
 * everything.
 */
#[IsRegistry(
    root: 'fixture.conforming',
    of: 'a conforming fixture registry',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class ConformingFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
