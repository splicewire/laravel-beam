<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * The same port with the pair carried across — `get()` over `resolve()`, `find()` over `tryResolve()`, on
 * Laravel's `findOrFail()`/`find()` split (registry-kernel 61 D3).
 *
 * The control case for {@see ThrowingHalfOnlyFixtureRegistry}: without it, a test asserting the defect
 * FIRES cannot tell a working check from one that fires on every port.
 */
#[IsRegistry(
    root: 'fixture.both-halves',
    of: 'a port publishing both halves of the miss pair',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class BothHalvesFixtureRegistry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for(self::class);
    }

    public function get(string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function find(string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }
}
