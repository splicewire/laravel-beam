<?php

namespace Splicewire\Beam\Particle\Registry;

use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Data\ResourceRegistry\ResourceSurfaceData;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\Contracts\TenantResolver;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Beam's default {@see ResourceSurfaceLocator}: the realm facts beam can read, and nothing a host owns.
 *
 * Per realm the key belongs to ({@see ParticleResourceRegistry::realmsFor()}, the membership authority):
 *
 *  - `central` — the realm's own flag.
 *  - `requiresTenant` — asked of the {@see TenantResolver} for the realm's EFFECTIVE definition
 *    ({@see RealmRegistry::effective()}), because a collapsed `user` realm composes through `tenant` and
 *    reads its records inside a tenant exactly as the tenant realm does. Asking the raw definition would
 *    call the account realm tenant-free on a deployment where it is not.
 *  - `actorScoped` — the realm is the account realm, whose records are the viewer's own.
 *
 * A realm key this host never registered is SKIPPED rather than thrown on: which realms a membership list
 * names is host config, and a reading surface that dies on one stale entry cannot report on the rest.
 *
 * The host half is two hooks, both empty here — {@see leaf()} and {@see navSeated()}. A host subclasses
 * this and supplies them from its own route plan and navigation.
 */
class RealmResourceSurfaceLocator implements ResourceSurfaceLocator
{
    public function __construct(
        protected ParticleResourceRegistry $particles,
        protected RealmRegistry $realms,
        protected TenantResolver $tenancy,
    ) {}

    public function surfacesFor(string $key, ?string $section): array
    {
        $surfaces = [];
        $accountRealm = $this->accountRealmKey();

        foreach ($this->particles->realmsFor($key) as $realm) {
            $definition = $this->realms->tryResolve($realm);

            if (! $definition instanceof RealmDefinition) {
                continue;
            }

            $leaf = $this->leaf($key, $realm);

            $surfaces[] = new ResourceSurfaceData(
                realm: $realm,
                central: $definition->central,
                requiresTenant: $this->requiresTenant($definition),
                actorScoped: $accountRealm !== null && $realm === $accountRealm,
                routeName: $leaf['routeName'] ?? null,
                href: $leaf['href'] ?? null,
                recordRouteName: $leaf['recordRouteName'] ?? null,
                recordHref: $leaf['recordHref'] ?? null,
                recordMounts: $leaf['recordMounts'] ?? null,
                navSeat: $section !== null && $leaf !== null && $this->navSeated($key, $section, $realm),
            );
        }

        return $surfaces;
    }

    /**
     * The route leaf `$realm` emits for `$key`, or null when it emits none (or this host cannot say).
     *
     * @return array{routeName: string, href: string, recordRouteName?: ?string, recordHref?: ?string, recordMounts?: ?string}|null
     */
    protected function leaf(string $key, string $realm): ?array
    {
        return null;
    }

    /** Does `$realm`'s navigation carry a seat for `$key` in `$section`? Unknown here, so no. */
    protected function navSeated(string $key, string $section, string $realm): bool
    {
        return false;
    }

    private function requiresTenant(RealmDefinition $definition): bool
    {
        try {
            $effective = $this->realms->effective($definition->key);
        } catch (\Throwable) {
            $effective = $definition;
        }

        return $this->tenancy->resolvesTenantFor($effective);
    }

    private function accountRealmKey(): ?string
    {
        try {
            return $this->realms->user()->key;
        } catch (\Throwable) {
            return null;
        }
    }
}
