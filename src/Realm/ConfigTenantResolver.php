<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm;

use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\Contracts\TenantResolver;

/**
 * The default {@see TenantResolver}: a realm resolves a tenant when it is one of the configured
 * tenant-bearing realms AND tenancy is enabled for this deployment (`config('frame.tenancy')`, default
 * true). Central/operator and user realms are never tenant-bearing.
 *
 * The deployment-shape switch stays config-driven (a single-tenant satellite sets `frame.tenancy=false`
 * and the tenant realm resolves centrally — the exact behaviour the retired `$tenancy` flag gave) but no
 * longer rides the agnostic `RealmDefinition`. WHICH realms are tenant-bearing is this resolver's config
 * (default `['tenant']`), not a per-realm flag — a host with differently-keyed tenant realms binds its
 * own instance.
 */
final class ConfigTenantResolver implements TenantResolver
{
    /** @param list<string> $tenantRealmKeys the realm keys that resolve a tenant when tenancy is on */
    public function __construct(private readonly array $tenantRealmKeys = ['tenant']) {}

    public function resolvesTenantFor(RealmDefinition $realm): bool
    {
        return in_array($realm->key, $this->tenantRealmKeys, true)
            && (bool) config('frame.tenancy', true);
    }
}
