<?php

namespace Splicewire\Beam\Realm;

use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\Contracts\TenantResolver;

/**
 * The default {@see TenantResolver}: a realm resolves a tenant when it is one of the configured
 * tenant-bearing realms AND this deployment uses STANCL-IDENTIFIED TENANCY (`config('frame.tenancy')`,
 * default true). Central/operator and user realms are never tenant-bearing.
 *
 * `config('frame.tenancy')` is the deployment's tenant-IDENTIFICATION-MODE switch, NOT a tenant count:
 *   - true (default): stancl-identified tenancy — a tenant is a domain/path with a per-tenant database, so
 *     the tenant realm resolves a (current) stancl tenant.
 *   - false: the host does not identify tenants via stancl. That includes a MEMBERSHIP-identified host,
 *     where the tenant is a team / `tenant_users` seat on the central connection (still MULTI-tenant), as
 *     well as a single-tenant install. `false` only says "no stancl tenant to resolve here", so downstream
 *     (e.g. the frame nav projection) uses the membership-seated path instead of requiring a current stancl
 *     tenant + actor mirror. (This replaces the retired per-realm `$tenancy` flag.)
 *
 * WHICH realms are tenant-bearing is this resolver's config (default `['tenant']`), not a per-realm flag —
 * a host with differently-keyed tenant realms binds its own instance.
 */
class ConfigTenantResolver implements TenantResolver
{
    /** @param list<string> $tenantRealmKeys the realm keys that resolve a tenant when tenancy is on */
    public function __construct(private array $tenantRealmKeys = ['tenant']) {}

    public function resolvesTenantFor(RealmDefinition $realm): bool
    {
        return in_array($realm->key, $this->tenantRealmKeys, true)
            && (bool) config('frame.tenancy', true);
    }
}
