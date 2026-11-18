<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Rushing\PermissionCascade\Support\CredentialScope;
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
 *
 * ## A scoped credential narrows the permission plane (ADR-0109; standwell gap-assessment-build 36)
 *
 * A call made with a scoped token is allowed only when the token's abilities also cover the permission the
 * check names: `effective = principal may ∩ credential scope`. Measured before this (standwell 02): a token
 * scoped to `entity.view` got 200 on `declarations.check`, because a flat permission name asked of the Gate
 * is answered by spatie's `before` and never reaches the cascade's `BaseModelPolicy`, the one place the
 * scope was applied. So the narrowing is applied HERE, on the two checks that ask a flat permission name:
 * a subject-bearing check whose ability is a name (`declarations.check` on `Entity`), and a subject-free
 * override target asked as a permission (launch 72). The scope comes from the permission cascade's
 * {@see CredentialScope} seam: the host binds its source (beam-accounts' bearer-token resolver), the
 * null default is unscoped, so a session, a `['*']` token, a guest and MCP over stdio are unchanged.
 * MCP's tool gate (beam-mcp's `EntitlementToolAuthorizer`) asks a subject-free, non-overridden ability,
 * which is the entitlement plane, so a scoped bearer on MCP's HTTP transport is NOT narrowed here either.
 * It can only subtract. Two checks are not matched against the scope as raw names, on purpose:
 *
 *   - a bare policy VERB (`view`, `update`) with a subject: the policy owns the name the verb derives to
 *     (`entity.view`, `entity.own.view`); checking the raw verb against a scope of permission names would
 *     refuse every scoped token. Under a scoped credential, a verb on a cascade-backed subject is asked of
 *     its `BaseModelPolicy` directly ({@see cascadePolicyPermits()}), because a host's `Gate::before` (the
 *     flagship's Root) can answer the Gate before that policy runs. A policy outside the cascade is not
 *     narrowed yet (standwell gap-assessment-build 39).
 *   - the ENTITLEMENT plane (`entitlement:{key}`): a plan feature of the tenant, not a principal
 *     permission, so it is not in a token's vocabulary (ADR-0109 decision 1).
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
     * @param  bool  $overridden  true when `$ability` is a mount's `abilityOverrides` TARGET (launch ticket 72, root
     *                            Option A). A subject-free override target that is not written `entitlement:<x>`
     *                            names a role permission (tower's tenant `site.edit`), so it is asked of the Gate
     *                            as itself — the actor's `can('<permission>')` — instead of as an entitlement nothing
     *                            defines. An `entitlement:`-prefixed target, asked as itself, IS the entitlement
     *                            plane; a declared (non-overridden) ability is unchanged.
     */
    public function allows(mixed $actor, string $ability, mixed $subject = null, bool $overridden = false): bool
    {
        $gate = $this->gate->forUser($actor);

        if ($subject !== null) {
            return $gate->allows($ability, $subject)
                && $this->credentialPermits($ability)
                && $this->cascadePolicyPermits($actor, $ability, $subject);
        }

        if ($overridden) {
            return $gate->allows($ability) && $this->credentialPermits($ability);
        }

        return $gate->allows($this->entitlementAbility($ability));
    }

    /**
     * Whether the acting credential's scope covers `$ability` (ADR-0109, see the class docblock). A bare
     * policy verb and an `entitlement:` key are not permission names, so they pass; the policy narrows a
     * verb's derived name itself. A host that never registered the cascade's seam is unscoped.
     */
    protected function credentialPermits(string $ability): bool
    {
        if (! $this->isPermissionName($ability) || ! app()->bound(CredentialScopeResolver::class)) {
            return true;
        }

        return app(CredentialScope::class)->permits($ability);
    }

    /**
     * Under a scoped credential, a bare policy verb on a cascade-backed subject must also be allowed by that
     * {@see BaseModelPolicy} itself, the place the cascade intersects the derived name (`hook.update`) with the
     * scope. The Gate's yes does not prove the policy ran: a host's `Gate::before` (the flagship's Root) answers
     * before it, so a Root token scoped to `entity.view` would otherwise reach a write. Asking the policy directly
     * leaves the principal half to it (`$user->can()`, where Root's `before` still answers) and keeps its own
     * rungs (steward, grants, reach, `#[UseCascadePolicy]` overrides) as they are. Unscoped calls, a guest, a
     * flat name (narrowed above) and a policy outside the cascade are unchanged here; the last is ticket 39.
     */
    protected function cascadePolicyPermits(mixed $actor, string $ability, mixed $subject): bool
    {
        if ($actor === null
            || $this->isPermissionName($ability)
            || str_starts_with($ability, self::ENTITLEMENT_PREFIX)
            || ! app()->bound(CredentialScopeResolver::class)
            || ! app(CredentialScope::class)->isScoped()) {
            return true;
        }

        $policy = $this->gate->getPolicyFor($subject);
        $method = Str::camel($ability);

        if (! $policy instanceof BaseModelPolicy || ! is_callable([$policy, $method])) {
            return true;
        }

        $arguments = is_string($subject) ? [] : [$subject];

        if (method_exists($policy, 'before')) {
            $before = $policy->before($actor, $ability, ...$arguments);

            if ($before !== null) {
                return $before instanceof Response ? $before->allowed() : (bool) $before;
            }
        }

        $result = $policy->{$method}($actor, ...$arguments);

        return $result instanceof Response ? $result->allowed() : (bool) $result;
    }

    /** A flat namespaced name (`declarations.check`, `tenant:provision`), not a bare verb or an entitlement key. */
    protected function isPermissionName(string $ability): bool
    {
        return ! str_starts_with($ability, self::ENTITLEMENT_PREFIX)
            && (str_contains($ability, '.') || str_contains($ability, ':'));
    }

    /** The inverse of {@see allows()}, for call sites that read better negated. */
    public function denies(mixed $actor, string $ability, mixed $subject = null, bool $overridden = false): bool
    {
        return ! $this->allows($actor, $ability, $subject, $overridden);
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
