<?php

namespace Splicewire\Beam\Realm;

use Illuminate\Contracts\Auth\Access\Gate;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Realm\RealmDefinition;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Beam's answer to frame's {@see ResourceAccessGate}: **realm membership is an authorization, not a
 * projection.**
 *
 * ## The defect this closes
 *
 * Measured 2026-09-12 at `https://fresh-tower.test` as `demo-member`, an ordinary tenant user whose
 * `os.operate` entitlement is false and who is correctly 403'd at `/operator`:
 *
 * ```
 * GET /frame/resources/users    → 200   every user row, with its email
 * GET /frame/resources/teams    → 200   every team
 * GET /frame/resources/tenants  → 200   the tenant roster, with each owner's email
 * ```
 *
 * The host had already placed `users` and `teams` in the operator realm (`config('frame.realms')`)
 * and hard-gated that realm (`beam.core.realm_gates.operator = ['entitlement' => 'os.operate']`).
 * Both declarations were read — by the NAV projection and by {@see RealmManifestProjector} — and
 * neither was ever asked at the socket. Membership decided which links were *drawn*. So the estate
 * had a realm boundary in every manifest and none on the wire.
 *
 * ## The rule, and what each arm is deliberately NOT
 *
 * Given a resource, {@see ParticleResourceRegistry::realmsFor()} — "the declared membership
 * authority", and the only reader that climbs both membership rungs — names its realms.
 *
 *  1. **No realms ⇒ permit.** An unrealmed resource is not a resource someone forgot to protect; it
 *     is one this host never placed in a console. The estate leans on this: every starter
 *     deliberately leaves `members`, `invitations` and `tokens` out of `frame.realms` (their config
 *     carries four paragraphs on why) while the socket still serves them, scoped per team, and
 *     `G1-BEAM-SCOPE-ISOLATION` is the spec that proves that scoping. Denying here would break the
 *     row-level-scope posture ADR-0156 §83 makes the deliberate design for a filterable resource, on
 *     a question about realms it has no bearing on. ⚠️ The corollary is real and is the HOST's to
 *     answer: a resource that must be operator-only must SAY so, in `config('frame.realms')`,
 *     `beam.core.resources.realm_map`, or at its own `register($resource, ['operator'])`.
 *     `tenants` on the tower starter was exactly this case and is now listed.
 *  2. **Any UNGATED realm ⇒ permit.** A resource in both the tenant and operator realms is reachable
 *     through the tenant one, and the tenant realm declares no gate. Refusing it would make the
 *     operator realm's membership *subtract* access, which is the opposite of what membership means
 *     everywhere else it is read.
 *  3. **Otherwise, hold ANY of the gating realms' entitlements.** Not all: membership is a union, and
 *     a principal entitled to one realm the resource lives in has a legitimate door to it.
 *
 * ## Which ability a realm gates on
 *
 * {@see abilityFor()}. A DECLARED `beam.core.realm_gates` entry wins — that key already exists, is
 * already read by {@see RealmManifestProjector}, and this makes the socket the second consumer of
 * one declaration rather than a second declaration. A CENTRAL realm with no declared gate falls back
 * to `entitlement:os.operate`, which is not a new vocabulary either: it is the estate's one staff
 * key, hardcoded on the same grounds in
 * `Splicewire\Beam\Accounts\Authorization\UserPolicy` ("there is ONE staff vocabulary in the estate")
 * and in `Ops\ImpersonateUser`. A host that spells its operator key differently declares a gate and
 * the fallback never runs.
 *
 * The fallback is what keeps an omission and a decision spelled differently. Without it, a host that
 * lists resources in its operator realm and forgets the gate entry gets *exactly* the measured
 * defect back, silently, and the config comment "Empty (the default) gates NOTHING" would be the
 * whole security posture of the operator console.
 *
 * `mode` (`hard`/`soft`) is deliberately NOT read here. It governs whether an unentitled principal
 * SEES the realm in a projected manifest — a launcher/monetization question. A soft-gated realm is
 * visible-but-locked, and serving its rows to the locked principal would be the lock meaning nothing.
 *
 * ## Fail-closed on an unknown ability
 *
 * `entitlement:{key}` abilities are defined at boot only for the known key universe
 * (`app.entitlements` ∪ `beam.core.entitlements.keys`). An ability nobody defined is denied by
 * Laravel's own Gate, so a host that gates a realm on a key it never declared refuses rather than
 * admits. That is inherited deny-by-default, not a branch of our own — the same mechanism
 * `Splicewire\Beam\Write\GateWriteGate` relies on.
 */
class RealmEntitlementResourceGate implements ResourceAccessGate
{
    /**
     * The estate's one staff entitlement, used when a CENTRAL realm declares no gate of its own.
     *
     * The same literal and the same reasoning as `Splicewire\Beam\Accounts\Authorization\UserPolicy`'s
     * staff check — named in prose rather than `@see`d, because that class lives in a package beam does
     * not depend on and an import here would invert the layering to decorate a docblock.
     */
    public const CentralRealmEntitlement = 'os.operate';

    public function __construct(
        protected ParticleResourceRegistry $particles,
        protected RealmRegistry $realms,
        protected Gate $gate,
    ) {}

    public function allowsResource(ResourceDefinition $definition): bool
    {
        $realms = $this->particles->realmsFor($definition->key);

        if ($realms === []) {
            return true;
        }

        $abilities = [];

        foreach ($realms as $realm) {
            $ability = $this->abilityFor($realm);

            if ($ability === null) {
                return true;
            }

            $abilities[] = $ability;
        }

        foreach ($abilities as $ability) {
            if ($this->gate->allows($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Gate ability a realm's resources are gated on, or null when the realm gates nothing.
     *
     * Read live rather than resolved once: `realm_gates` is ordinary config a test or a host boot
     * step can change, and the realm registry is computed on read for the same reason.
     */
    protected function abilityFor(string $realm): ?string
    {
        $gates = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));
        $declared = $gates[$realm]['entitlement'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return 'entitlement:'.$declared;
        }

        $definition = $this->realms->tryResolve($realm);

        if ($definition instanceof RealmDefinition && $definition->central) {
            return 'entitlement:'.self::CentralRealmEntitlement;
        }

        return null;
    }
}
