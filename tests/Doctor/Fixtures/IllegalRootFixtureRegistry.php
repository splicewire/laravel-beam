<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * A root no `Key` will parse: uppercase and a space. Never constructed by the audit, which only reflects.
 */
#[IsRegistry(
    root: 'Fixture.Illegal Root',
    of: 'a fixture registry whose root is not a key',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class IllegalRootFixtureRegistry
{
    /** @var array<string, mixed> */
    protected array $entries = [];
}
