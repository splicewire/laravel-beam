<?php

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;
use Splicewire\Beam\Realm\Contracts\TenantResolver;

/**
 * The tenant-workspace realm preset (realm-architecture ticket 08 slice D): the non-central workspace
 * realm mounted at the root (`/`) with its own auth. This is the TENANT-BEARING realm — the default key
 * the {@see TenantResolver} resolves against (slice B). Whether a tenant
 * is actually resolvable on a given deployment stays the resolver's config call, NOT a flag on the realm.
 *
 * Presets: `central: false`, `routeBase: '/'`, `guard: null`. A capability package (e.g.
 * `laravel-satellite-multi-tenancy`) declares its own differently-shaped `tenant` realm and last-wins.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class TenantRealm extends Realm
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        string $key = 'tenant',
        string $routeBase = '/',
        ?string $guard = null,
        bool $central = false,
        array $stack = [],
    ) {
        parent::__construct(
            key: $key,
            routeBase: $routeBase,
            guard: $guard,
            central: $central,
            stack: $stack,
        );
    }
}
