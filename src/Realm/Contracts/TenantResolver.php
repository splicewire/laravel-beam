<?php

namespace Splicewire\Beam\Realm\Contracts;

use Schemastud\Frame\Realm\RealmDefinition;

/**
 * The port answering "does this realm resolve a tenant on THIS deployment?" — the re-home of the retired
 * `RealmDefinition::$tenancy` bool (realm-architecture ticket 08, slice B).
 *
 * Tenant resolvability was never a structural property of a realm; it's a **deployment-shape** switch: the
 * same realm set serves a subdomain-scoped multi-tenant SaaS (the tenant realm resolves a tenant) AND a
 * single-tenant satellite (it resolves centrally / not at all), with no code fork. Carrying that on the
 * agnostic `RealmDefinition` DTO leaked a deployment concern into the shape; it belongs behind this port,
 * bound per install — a satellite binds a resolver that returns false, a SaaS one that returns true for
 * its tenant realm. WHICH realm is tenant-bearing is the resolver's knowledge (identified by key/type),
 * not a flag on every realm.
 */
interface TenantResolver
{
    /** Does `$realm` resolve a tenant on this deployment? (Deny for central/non-tenant realms.) */
    public function resolvesTenantFor(RealmDefinition $realm): bool;
}
