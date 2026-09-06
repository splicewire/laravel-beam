<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;

/**
 * Complete: implements the contract and writes every slot the gate asks for. The control case — without
 * one, a test asserting that a defect FIRES cannot tell a working check from a check that fires on
 * everything.
 */
#[IsRegistry(
    root: 'fixture.conforming',
    onDuplicate: OnDuplicate::Supersede,
    description: 'a conforming fixture registry',
)]
class ConformingFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
