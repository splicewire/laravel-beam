<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;

/**
 * Inherits `Supersede` without writing it — a policy that reads as a decision without being one.
 */
#[IsRegistry(
    root: 'fixture.silent-duplicate',
    description: 'a fixture registry that never says what a duplicate does',
)]
class SilentDuplicateFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
