<?php

namespace Splicewire\Beam\Tests\Entitlements;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * A canned kernel EntitlementResolver for beam-core tests — returns a fixed key set regardless of
 * principal, so the two-plane predicate / can-map / manifest projection can be exercised in isolation
 * (ticket 08: "Bind a fake EntitlementResolver so beam-core tests stay isolated"). No commerce/tenancy.
 */
class FakeEntitlementResolver implements EntitlementResolver
{
    /** @param array<int, string> $held */
    public function __construct(private array $held = []) {}

    public function entitlementsFor(mixed $principal): array
    {
        return $this->held;
    }
}
