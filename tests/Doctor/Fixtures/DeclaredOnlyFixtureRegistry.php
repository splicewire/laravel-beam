<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Declares but does not implement — the state ~50 estate classes are in until registry-kernel 37/38, and
 * the reason the contract check does not gate yet.
 */
#[IsRegistry(
    root: 'fixture.declared-only',
    of: 'a declared but non-conforming fixture registry',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class DeclaredOnlyFixtureRegistry
{
    /** @var array<string, mixed> */
    protected array $entries = [];
}
