<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;

/**
 * A root no `Key` will parse: uppercase and a space. Never constructed by the audit, which only reflects.
 */
#[IsRegistry(
    root: 'Fixture.Illegal Root',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'a fixture registry whose root is not a key',
)]
class IllegalRootFixtureRegistry
{
    /** @var array<string, mixed> */
    protected array $entries = [];
}
