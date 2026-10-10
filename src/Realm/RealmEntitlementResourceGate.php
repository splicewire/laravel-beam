<?php

namespace Splicewire\Beam\Realm;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate as GateFacade;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
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
 * An explicit route `realm` requires membership in that realm and its entitlement. The union
 * rules below apply only when the route does not select a realm.
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
 *
 * ## The list's read decision is asked FIRST, before any realm arm
 *
 * On the manifest controller, {@see ResourceVisibility::listable()}. Realm membership answers *which
 * door*; it cannot replace the resource's own read boundary. The manifest and its nav offer the
 * corresponding list request, so they ask the same {@see ResourceReadGuard} decision as that list before
 * projecting a resource. Other Frame sockets retain {@see ResourceVisibility::readable()}: their own
 * list/detail/summary handlers ask the full guard with route-specific parent and filter context, which a
 * reach gate cannot precompute without breaking valid relative reads.
 */
class RealmEntitlementResourceGate implements ResourceAccessGate
{
    protected bool $actorSpecified = false;

    protected ?Authenticatable $actor = null;

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
        protected ?ResourceVisibility $visibility = null,
    ) {
        $this->visibility ??= new ResourceVisibility($particles, $gate);
    }

    public function allowsResource(ResourceDefinition $definition): bool
    {
        $actor = $this->actorSpecified ? $this->actor : Auth::user();
        $mountedRealm = request()->route('realm');
        $mountedRealm = is_string($mountedRealm) && $mountedRealm !== '' ? $mountedRealm : null;

        $visible = $this->isManifestRequest()
            ? $this->visibility->listable($definition, $actor, $mountedRealm)
            : $this->visibility->readable($definition, $actor);

        if (! $visible) {
            return false;
        }

        $realms = $this->particles->realmsFor($definition->key);

        // An explicitly mounted realm is the door being used; other memberships cannot open it.
        if ($mountedRealm !== null) {
            if (! in_array($mountedRealm, $realms, true)) {
                return false;
            }
            $ability = $this->abilityFor($mountedRealm);

            return $ability === null || $this->gate->allows($ability);
        }

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

    /** Whether Frame is projecting the actor's advertised resource catalog rather than serving data. */
    private function isManifestRequest(): bool
    {
        $route = request()->route();

        return $route instanceof Route
            && is_a($route->getControllerClass(), FrameManifestController::class, true);
    }

    /** A copy whose reach and declared-read gates are bound to one explicit actor, including a guest. */
    public function forActor(?Authenticatable $actor): self
    {
        $gate = GateFacade::forUser($actor);
        $copy = new self($this->particles, $this->realms, $gate, new ResourceVisibility($this->particles, $gate));
        $copy->actorSpecified = true;
        $copy->actor = $actor;

        return $copy;
    }

    /**
     * Has the caller been authorized for this resource's population BY a realm gate? True only when every
     * realm the resource belongs to is gated and the caller passes one of those gates (or, for an
     * explicitly mounted realm, that realm's gate). An ungated membership lets anyone through the socket,
     * so it cannot vouch for a population, and a resource in no realm has no realm authority at all.
     *
     * {@see ResourceReadGuard::inspectRead()} counts this as a read
     * authorization beside a policy, tenancy and a query predicate (ux-demo replay 2026-09-23).
     */
    public function entitledThroughRealm(string $key, ?Gate $as = null, ?string $mountedRealm = null): bool
    {
        // `$as` is a Gate already bound to the actor being asked about (ResourceReadGuard::inspectReadFor());
        // without one, the ambient user is asked.
        $gate = $as ?? $this->gate;

        $realms = $this->particles->realmsFor($key);

        if ($realms === []) {
            return false;
        }

        $routeRealm = request()->route('realm');
        if (is_string($routeRealm) && $routeRealm !== '') {
            if ($mountedRealm !== null && $mountedRealm !== $routeRealm) {
                return false;
            }

            $mountedRealm = $routeRealm;
        }
        if (is_string($mountedRealm) && $mountedRealm !== '') {
            if (! in_array($mountedRealm, $realms, true)) {
                return false;
            }
            $ability = $this->abilityFor($mountedRealm);

            return $ability !== null && $gate->allows($ability);
        }

        $abilities = [];
        foreach ($realms as $realm) {
            $ability = $this->abilityFor($realm);
            if ($ability === null) {
                return false;
            }
            $abilities[] = $ability;
        }

        foreach ($abilities as $ability) {
            if ($gate->allows($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Gate ability a realm's resources are gated on, or null when the realm gates nothing — the one
     * rule in {@see RealmGateAbility}, which the realm's dashboard declaration reads too.
     */
    protected function abilityFor(string $realm): ?string
    {
        return RealmGateAbility::for($realm, $this->realms);
    }
}
