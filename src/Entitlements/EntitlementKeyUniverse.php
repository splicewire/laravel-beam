<?php

namespace Splicewire\Beam\Entitlements;

use Closure;

/**
 * Every entitlement key this host knows, read at the moment it is asked (tutorial-dx 04): `array_keys(config('app.entitlements'))`
 * ∪ `config('beam.core.entitlements.keys')` ∪ every contributed source (beam-accounts contributes its bundles' keys and
 * its grant sources' declared keys). Nothing is frozen at boot, so a key declared in a host's `boot()`, or in a bundle, is
 * known when it is checked.
 *
 * Knowing a key is what lets beam DEFINE its `entitlement:{key}` ability ({@see \Splicewire\Beam\BeamServiceProvider});
 * it never grants anything. A key outside the universe stays undefined, and Exec ADR-0001's "an undefined key denies
 * everyone" still applies to it; `UndefinedEntitlementKeyAudit` names the ones a host references.
 */
final class EntitlementKeyUniverse
{
    /** @var list<Closure(): iterable<string>> */
    private array $sources = [];

    /** @var list<Closure(): void> */
    private array $listeners = [];

    /** Add a source of keys, read on every {@see keys()} call. */
    public function contribute(Closure $source): void
    {
        $this->sources[] = $source;

        foreach ($this->listeners as $listener) {
            $listener();
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [
            ...array_keys((array) config('app.entitlements', [])),
            ...(array) config('beam.core.entitlements.keys', []),
        ];

        foreach ($this->sources as $source) {
            foreach ($source() as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique(array_map('strval', $keys)));
    }

    public function knows(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /** @internal beam's provider defines the new keys' abilities when a source is contributed after boot */
    public function whenContributed(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }
}
