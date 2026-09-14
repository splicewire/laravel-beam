<?php

namespace Splicewire\Beam\Realm;

use Splicewire\Beam\Dashboard\RealmDashboard;

/**
 * The Gate ability a realm's resources are gated on — `entitlement:{declared}` for a realm with a
 * `beam.core.realm_gates.{realm}.entitlement`, `entitlement:os.operate` for a central realm with none,
 * and null for a realm that gates nothing.
 *
 * Written once, here, because two readers need the same answer at two different times:
 * {@see RealmEntitlementResourceGate} asks it per request to refuse the socket, and the realm's dashboard
 * declaration ({@see RealmDashboard::abilityFor()}) asks it at registration to
 * write the same ability down as its `policy:`. A dashboard that mirrored the gate's rule line-for-line
 * would open a door the realm does not the day one of the two copies moved.
 *
 * Read live rather than resolved once: `realm_gates` is ordinary config a test or a host boot may set
 * after the provider ran.
 */
final class RealmGateAbility
{
    public static function for(string $realm, RealmRegistry $realms): ?string
    {
        $gates = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));
        $declared = $gates[$realm]['entitlement'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return 'entitlement:'.$declared;
        }

        if ($realms->tryResolve($realm)?->central === true) {
            return 'entitlement:'.RealmEntitlementResourceGate::CentralRealmEntitlement;
        }

        return null;
    }
}
