<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * A port: its own vocabulary over a held kernel store, publishing the THROWING half and nothing else.
 *
 * This is the shape registry-kernel ticket 38 landed on `data-filters.resources` — `get()` over
 * `resolve()`, no nullable twin — which turned two asserted 404s in the flagship into 500s because a host's
 * `catch` no longer matched the kernel's miss type and there was no `find()`-shaped accessor to move to.
 * Everything the sweep had reported green. This fixture is that defect, held still.
 *
 * It deliberately does NOT implement the contract: a conforming forwarder answers both halves by
 * construction, so the population this check has anything to say about is exactly the ports.
 */
#[IsRegistry(
    root: 'fixture.throwing-half-only',
    of: 'a port publishing only the throwing half of the miss pair',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
)]
class ThrowingHalfOnlyFixtureRegistry
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
}
