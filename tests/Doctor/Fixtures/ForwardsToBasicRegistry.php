<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;

/**
 * The seven-method forward onto a held {@see BasicRegistry} — the composition shape the contract is built to
 * be used through, reduced to the minimum a conformance FIXTURE needs.
 *
 * Shared by the fixtures of {@see RegistryConformanceAudit}'s tests so that what
 * differs between them is only the DECLARATION, which is the thing under test. A fixture carrying its own
 * hand-written seven methods would let a typo in one of them read as a declaration defect.
 */
trait ForwardsToBasicRegistry
{
    protected BasicRegistry $entries;

    public function register(RegistryKey|string $key, mixed $entry, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    /** @return list<mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }
}
