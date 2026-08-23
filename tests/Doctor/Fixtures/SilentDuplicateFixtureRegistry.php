<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Inherits `Supersede` without writing it — a policy that reads as a decision without being one.
 */
#[IsRegistry(
    root: 'fixture.silent-duplicate',
    of: 'a fixture registry that never says what a duplicate does',
    arity: RegistryArity::PickOne,
)]
class SilentDuplicateFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }
}
