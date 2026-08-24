<?php

namespace Splicewire\Beam\Authorization;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\Exceptions\MissReason;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * registry-kernel ticket 27 — the REGISTRY half of the cross-transport ability resolver, and the third
 * copy of one shape rather than a third decision.
 *
 * `Splicewire\Beam\Mcp\Authorization\EntitlementToolAuthorizer` is this class for MCP tools; this is the
 * same adapter for Popcorn registry entries. Both forward `(ability)` to the shared
 * {@see AbilityResolver} with NO subject, which lands on the entitlement branch, which is the gate.
 * Neither holds an ability list, a mapping table or a cache — anything thicker would be a second copy of
 * a decision that already has one home, which is the drift the single resolver exists to prevent.
 *
 * ## Why it is called Entitlement and not Particle
 *
 * Ticket 27 charters "Beam's particle authorizer", but there is exactly ONE authorizer for the estate
 * (registry-kernel ticket 09 D7) and {@see RegistryIndex} pushes it into
 * every registry it holds. So this gates whatever any beam-side registry declares an ability for, not
 * particle resources specifically — naming it `Particle*` would promise a scope the seam cannot have.
 * `Entitlement` names the plane it actually answers on, matching its MCP twin.
 *
 * ## The key is accepted and IGNORED
 *
 * Exactly as `EntitlementToolAuthorizer` ignores its `$toolClass`: the entitlement gate is
 * instance-blind, so admitting the key into the decision would make the transports answer different
 * questions. It stays in the signature because the kernel seam passes it and a host override may care.
 *
 * ## Ambient authentication is never read
 *
 * The actor arrives from {@see ActorPort}, which is the only place ambient authentication is read.
 * That is also why the kernel seam has no actor parameter at all — see {@see Authorizer}.
 *
 * ## It is shipped, NOT installed — and beam's case is stronger than popcorn's
 *
 * No package installs an authorizer; the host app does (registry-kernel ticket 20 D9). `laravel-popcorn`
 * ships `GateAuthorizer` and installs it nowhere on the argument that `Gate::allows()` returns FALSE for
 * an undefined ability, so a default install turns "nobody has written that policy yet" into silent
 * invisibility.
 *
 * Beam's version of that failure is reachable without anyone forgetting anything.
 * `BeamServiceProvider::registerEntitlementAbilities()` defines an `entitlement:{key}` ability only for
 * keys the host lists under `app.entitlements` / `beam.core.entitlements.keys`, and it returns EARLY —
 * defining none at all — when `EntitlementResolver` is unbound, which is any bare install without
 * `rushing/laravel-permission-cascade`. Installed by default, this class would therefore make every
 * gated entry in the estate vanish from a bare beam install, silently and correctly-looking. The host
 * opts in once it has an entitlement universe:
 *
 * ```php
 * Popcorn::authorizeWith($this->app->make(EntitlementRegistryAuthorizer::class));
 * ```
 *
 * Note the asymmetry that makes opting in safe rather than merely reversible: an entry that declared no
 * ability short-circuits inside the registry and never reaches an authorizer, so installing one cannot
 * narrow an already-open surface — it can only enforce gating entries asked for themselves.
 *
 * ## Nothing in the estate declares an ability yet
 *
 * Measured on ticket 27, 2026-08-24: zero production `register(..., ability: ...)` call sites across all
 * three vendors. This adapter is therefore correct and currently inert, and a host that installs it today
 * changes no read. See the ticket for what has to land before it gates anything.
 */
class EntitlementRegistryAuthorizer implements Authorizer
{
    public function __construct(
        protected AbilityResolver $abilities,
        protected ActorPort $actor,
    ) {}

    /**
     * Whether the caller may see the entry declaring `$ability`.
     *
     * Returns a bool and constructs nothing: the deny SHAPE belongs to the transport. The registry's own
     * answer to false is {@see MissReason::Filtered}, which renders
     * byte-identically to `Absent` so enumeration and a direct hit cannot disagree about whether a key
     * exists.
     *
     * The `entitlement:` prefix is {@see AbilityResolver}'s and stays there — `entitlementAbility()`
     * makes a double prefix impossible, so a bare key passes straight through and an already-prefixed
     * one is left alone.
     */
    public function allows(string $ability, RegistryKey $key): bool
    {
        return $this->abilities->allows($this->actor->actor(), $ability);
    }
}
