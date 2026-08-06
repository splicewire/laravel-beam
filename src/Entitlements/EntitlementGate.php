<?php

namespace Splicewire\Beam\Entitlements;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * Frame OS ticket 08 (ADR-0013 §2/§4): the entitlement gate — the beam-side unification of the two
 * authorization planes. The per-ACTION plane (may this principal perform this action on this subject?)
 * stays the existing policies + permission cascade; the FEATURE plane (which feature bundles does this
 * principal hold?) resolves HERE, through the domain-neutral kernel {@see EntitlementResolver} seam.
 *
 * `allows($key, $principal)` is a thin membership test: is the entitlement key in the set the bound
 * resolver reports for this principal? {@see EntitlementServiceProvider} registers a Laravel Gate ability
 * per known feature key (`entitlement:{key}`) that delegates here, so both planes answer one uniform
 * predicate — `Gate::allows('entitlement:workbench.enter')` and `Gate::allows('song', $song)` are asked
 * the same way, and the frontend never computes authz (it reads the emitted can-map / manifest).
 *
 * Inert by default: with the null kernel resolver (no host binding) every set is empty, so every feature
 * ability is denied and behaviour stays byte-for-byte until a host binds a resolver.
 *
 * Not to be confused with the commerce `Splicewire\Beam\Commerce\Entitlements\EntitlementGate` (a
 * capability-authorization seam over the plan cascade); this is the beam-core feature-membership gate that
 * consumes the kernel seam that adapter feeds.
 */
class EntitlementGate
{
    public function __construct(private EntitlementResolver $resolver) {}

    /**
     * Whether `$principal` holds the given entitlement key.
     *
     * @param  string  $entitlementKey  a feature key (e.g. `workbench.enter`, `own-a-song`)
     * @param  mixed  $principal  the user/tenant/credential principal; null defers to the resolver's own
     *                            request-time resolution (the kernel adapter resolves the acting tenant)
     */
    public function allows(string $entitlementKey, mixed $principal = null): bool
    {
        return in_array($entitlementKey, $this->resolver->entitlementsFor($principal), true);
    }
}
