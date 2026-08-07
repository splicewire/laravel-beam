<?php

namespace Splicewire\Beam\Realm\Attributes;

use Attribute;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\RealmDiscovery;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The generic realm-declaration attribute (realm-architecture ticket 08 slice D). Placed on a host
 * **realm-marker class** — a plain class whose sole purpose is to carry this attribute — it is the
 * single source of truth from which {@see RealmDiscovery} reflects a
 * {@see RealmDefinition} at boot and self-registers it onto the {@see RealmRegistry},
 * ADDITIVELY onto the three imperative base realms (last-wins by key). Mirrors frame's
 * `#[ParticleResource]` mechanism, but for realms — and lives in **beam**, never frame: frame owns the
 * agnostic {@see RealmDefinition} *shape* and must never learn the realm names "operator"/"tenant"/"user".
 *
 * This base attribute carries the full realm parameter surface with neutral defaults; the sibling
 * presets ({@see OperatorRealm}, {@see UserRealm}, {@see TenantRealm}) subclass it with per-type presets
 * so a host declares a realm by intent rather than re-specifying its routing axes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Realm
{
    /**
     * @param  string  $key  stable realm identity (the manifest route default + resource-realm map key)
     * @param  string  $routeBase  the SPA mount base for this realm's generated route tree (`/admin`, `/`, `/settings`)
     * @param  string|null  $guard  the default host guard key wrapping this realm's leaves (`root` → RequireRoot; null = the shell's own auth)
     * @param  bool  $central  whether this is THE central/operator realm (never tenant-scoped)
     * @param  list<string>  $stack  the realm keys this realm COMPOSES THROUGH (slice E); empty = sources itself
     */
    public function __construct(
        public string $key,
        public string $routeBase = '/',
        public ?string $guard = null,
        public bool $central = false,
        public array $stack = [],
    ) {}

    /** Project this attribute into the agnostic frame {@see RealmDefinition} the registry stores. */
    public function toDefinition(): RealmDefinition
    {
        return new RealmDefinition(
            key: $this->key,
            routeBase: $this->routeBase,
            guard: $this->guard,
            central: $this->central,
            stack: $this->stack,
        );
    }
}
