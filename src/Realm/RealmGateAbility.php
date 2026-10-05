<?php

namespace Splicewire\Beam\Realm;

use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Ia\RealmProfiles;

/**
 * The Gate ability a realm's resources are gated on — `entitlement:{declared}` for a realm whose gate
 * ({@see RealmProfiles::gate()}: `beam.core.realms.{realm}.gate`, else `beam.core.realm_gates.{realm}`) declares
 * an entitlement, `entitlement:os.operate` for a central realm with none,
 * and null for a realm that gates nothing.
 *
 * Written once, here, because two readers need the same answer at two different times:
 * {@see RealmEntitlementResourceGate} asks it per request to refuse the socket, and the realm's dashboard
 * declaration ({@see RealmDashboard::abilityFor()}) asks it at registration to
 * write the same ability down as its `policy:`. A dashboard that mirrored the gate's rule line-for-line
 * would open a door the realm does not the day one of the two copies moved.
 *
 * Read live rather than resolved once: the gate is ordinary config a test or a host boot may set
 * after the provider ran.
 */
final class RealmGateAbility
{
    public static function for(string $realm, RealmRegistry $realms): ?string
    {
        // One gate source for the door and the nav (ux-walkthrough UX-05): the realm profile's gate, else the
        // `realm_gates` alias entry, the same precedence RealmManifestProjector reads.
        $declared = (new RealmProfiles)->gate($realm)['entitlement'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return 'entitlement:'.$declared;
        }

        if ($realms->tryResolve($realm)?->central === true) {
            return 'entitlement:'.RealmEntitlementResourceGate::CentralRealmEntitlement;
        }

        return null;
    }
}
