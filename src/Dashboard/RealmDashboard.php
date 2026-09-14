<?php

namespace Splicewire\Beam\Dashboard;

use Splicewire\Beam\Doctor\DashboardTierAudit;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Realm\RealmGateAbility;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The naming and gating grammar of the one read-only dashboard resource a realm gets
 * (realm-dashboards ticket 04) — written once so the registration and backing in `splicewire/laravel-beam-ux`,
 * its nav and router leaves, and beam's own {@see DashboardTierAudit} cannot spell the
 * key, the route name, the path or the ability differently. It sits HERE, one package below the resource
 * that bears it, because the audit has to recognise the dashboard by name and beam cannot see beam-ux.
 *
 * ## Why the key carries the realm
 *
 * Frame's resource socket is mounted ONCE and realm-blind (only the manifest is mounted per realm), so a
 * single `dashboard` key could not know which realm's cards to stream. `{realm}-dashboard` is a distinct
 * resource per realm, a member of that realm only; the realm is derived from membership, never from the
 * route.
 *
 * ## Why the PATH is not the key
 *
 * The router leaf follows the declared route name's stem, which would put the operator dashboard at
 * `/operator/operator-dashboard`. The realm base already names the realm, so the leaf mounts at
 * `/{realmBase}/dashboard` — beam-ux's `RouteContextProjector` reads {@see PATH} for exactly this key.
 */
final class RealmDashboard
{
    /** The in-realm path segment the dashboard's list leaf mounts at, under the realm's `routeBase`. */
    public const PATH = 'dashboard';

    /** The label the declaration and its nav leaf carry — spelled once. */
    public const LABEL = 'Dashboard';

    /**
     * The Gate ability a dashboard declares in a realm that gates NOTHING — a non-central realm with no
     * `beam.core.realm_gates.{realm}.entitlement`.
     *
     * Such a realm admits every authenticated principal to every resource in it, so the honest ceiling for
     * its dashboard is the same: any authenticated actor. That is the posture an UNDECLARED `policy:` has —
     * but written down as a declared ability, so `ModelLessReadGateAudit` reads a decision rather than an
     * omission. beam-ux defines the ability as "a principal is present"; a host that wants a narrower door
     * redefines it from its own provider, which boots later and wins. The dashboard's SECOND gate is per
     * row: the backing keeps only the resources `ResourceVisibility::listable()` admits the actor to.
     */
    public const OPEN_ABILITY = 'realm-dashboard.view';

    public static function keyFor(string $realm): string
    {
        return $realm.'-dashboard';
    }

    public static function routeNameFor(string $realm): string
    {
        return ListRouteName::for(self::keyFor($realm));
    }

    public static function isKey(string $key, string $realm): bool
    {
        return $key === self::keyFor($realm);
    }

    /**
     * The `policy:` a realm's dashboard declares — the SAME ability string
     * {@see RealmEntitlementResourceGate} gates the realm's resources on, read from
     * the one rule in {@see RealmGateAbility}, and {@see OPEN_ABILITY} where that rule gates nothing.
     */
    public static function abilityFor(string $realm, RealmRegistry $realms): string
    {
        return RealmGateAbility::for($realm, $realms) ?? self::OPEN_ABILITY;
    }
}
