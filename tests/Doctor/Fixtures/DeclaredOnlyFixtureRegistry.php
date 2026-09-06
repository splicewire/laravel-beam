<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;

/**
 * Declares but does not implement — the state ~50 estate classes are in until registry-kernel 37/38, and
 * the reason the contract check does not gate yet.
 */
#[IsRegistry(
    root: 'fixture.declared-only',
    onDuplicate: OnDuplicate::Supersede,
    description: 'a declared but non-conforming fixture registry',
)]
class DeclaredOnlyFixtureRegistry
{
    /** @var array<string, mixed> */
    protected array $entries = [];
}
