<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Splicewire\Beam\Entitlements\EntitlementGate;

/**
 * particle-doctrine-convergence ticket 09 — the ONE question "may this actor invoke this?", asked
 * identically from every transport.
 *
 * There are two authorization planes and they are unequally shareable:
 *
 *   - The **per-action** plane needs a SUBJECT (the loaded model, or the class-string a cross-model
 *     operation names) and speaks bare policy verbs (`update`, `create`). It routes to the Gate/policy
 *     cascade, unchanged. This is the plane that genuinely differs between transports, because the MCP
 *     gate is deliberately instance-blind and has no subject to offer.
 *   - The **entitlement** plane is subject-free and actor-OPTIONAL, and was already transport-neutral
 *     before this class existed: beam registers one `entitlement:{key}` Gate ability per known feature
 *     key delegating to the {@see EntitlementGate}, which is structurally the same thing as MCP's flat
 *     namespaced ability string checked by set membership. So the subject-free branch here is a plain
 *     Gate lookup on the prefixed key — it deliberately introduces NO new indirection, and no
 *     transport-specific ability set exists anywhere to keep in sync.
 *
 * Two things this class refuses to do, both on purpose:
 *
 *   1. **It never reads ambient authentication.** The actor arrives as an argument, sourced from an
 *      {@see ActorPort}, because MCP over stdio has no ambient HTTP user.
 *   2. **It never constructs a denial.** It returns a bool. The TRANSPORT owns the deny shape: HTTP
 *      answers a forbidden status, MCP omits the tool from `tools/list` (and therefore refuses the
 *      call). Centralizing the denial would force one transport to speak the other's dialect.
 *
 * ## Command-line invocation is UNGATED, by policy
 *
 * Stated here rather than left implicit, because an unstated asymmetry bites and a stated one is a
 * policy. An artisan command projected as an MCP tool is gated on the MCP path ONLY — the tool's
 * declared ability is checked when the MCP server builds its context. Running that same command from a
 * shell (`php artisan surgeon:move --apply`) passes through NO resolver call and is therefore always
 * allowed. That is intentional under a trusted-shell model: shell access already implies filesystem and
 * database access, so a gate there would be theatre, not security. The consequence to keep in mind is
 * that the MCP gate is a gate on the AGENT, not on the operation: never rely on it as the only control
 * over something a shell user must not do.
 */
class AbilityResolver
{
    /** The prefix beam registers its entitlement Gate abilities under. */
    public const ENTITLEMENT_PREFIX = 'entitlement:';

    public function __construct(protected Gate $gate) {}

    /**
     * Whether `$actor` may invoke `$ability`, optionally against `$subject`.
     *
     * With a subject: the policy cascade decides, exactly as a hand-rolled `authorize()` call did.
     * Without one: the entitlement/ability-set lookup decides, via the `entitlement:{key}` Gate ability.
     *
     * @param  mixed  $actor  the acting principal from an {@see ActorPort}; null is allowed (guest on
     *                        the per-action plane, deferred principal resolution on the entitlement one)
     * @param  string  $ability  a bare policy verb (subject-bearing) or a flat namespaced ability key
     * @param  mixed  $subject  a model instance or a model class-string; null selects the subject-free plane
     */
    public function allows(mixed $actor, string $ability, mixed $subject = null): bool
    {
        $gate = $this->gate->forUser($actor);

        if ($subject !== null) {
            return $gate->allows($ability, $subject);
        }

        return $gate->allows($this->entitlementAbility($ability));
    }

    /** The inverse of {@see allows()}, for call sites that read better negated. */
    public function denies(mixed $actor, string $ability, mixed $subject = null): bool
    {
        return ! $this->allows($actor, $ability, $subject);
    }

    /**
     * Project a flat ability key onto the Gate ability beam registers for it. Already-prefixed keys pass
     * through, so a caller may name either form and a double prefix is impossible.
     */
    public function entitlementAbility(string $ability): string
    {
        return str_starts_with($ability, self::ENTITLEMENT_PREFIX)
            ? $ability
            : self::ENTITLEMENT_PREFIX.$ability;
    }
}
