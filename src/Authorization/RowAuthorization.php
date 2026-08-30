<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;

/**
 * The ROW plane of authorization — "which rows may this actor read?" — as one named idiom.
 *
 * The sibling of {@see AbilityResolver}, which owns the per-action plane ("may this actor invoke
 * this?"). The two are not interchangeable and the difference is the whole reason this class exists: a
 * policy verb answers a question about ONE record you already hold, and a list has no record to hold.
 * `ParticleController::show()` can call `authorize('view', $model)`; `index()` cannot, and says so —
 * *"a `filterable:false` resource has no data-filters query to gate its index, so its owner/inverse
 * `scope` closure is the ONLY read guard — without it the list would return every row across all
 * callers."*
 *
 * ## Why a named idiom rather than four more lines at each call site
 *
 * Measured 2026-08-30: **twelve** call sites already compute this by hand, and they do not agree.
 *
 *   - `CalendarData:97` / `CalendarEventData:83` / `CalendarSeriesData:76` / `RankTreeData:38` call
 *     `Gate::getPolicyFor($model)->scopeForUser(...)` with **no null check**, and fatal where no policy
 *     is bound.
 *   - `SiloQuery:35` / `FragmentQuery:47` / `SiloController:77` guard with
 *     `$policy !== null && method_exists($policy, 'scopeForUser')` and, when that fails, **return the
 *     query unscoped** — fail-OPEN, which is registry-kernel 72's defect promoted to a default.
 *
 * Three of twelve fail open, four fatal, and nothing anywhere states which is correct. That
 * disagreement is not a tidiness problem: it is the estate holding two opposite answers to
 * *"what should a read do when it cannot resolve its own guard?"*
 *
 * ## The three rulings this class fixes
 *
 * **1. Fail CLOSED.** An unresolvable guard yields no rows, never all rows. A read that cannot
 * establish who may see something must not decide that everyone may. This matches the posture landed on
 * `NotificationStatusData` (registry-kernel 72 section A) and overrules the three fail-open sites.
 *
 * **2. Return, and the caller must USE the return.** ⚠️ `FragmentQuery:47-49` calls
 * `$policy->scopeForUser($query, …)` and **discards the result**, then returns `$query`. Measured
 * 2026-08-30: that is safe *today* only because every branch of `scopeForUser` is
 * `return $query->where…()` and Eloquent mutates in place, so the returned builder is the same instance
 * (`$returned === $q` is true, and the discarded-return builder does carry `where 1 = 0`). It is safe by
 * **accident, not by contract** — the day one branch returns a clone, a `newQuery()`, or a re-`from()`ed
 * builder, both discarding sites stop scoping silently, with no type error, because the signature still
 * matches. Returning a builder from a `static` makes the discard impossible to write by accident.
 *
 * **3. Type the check, do not duck-type it.** The `method_exists($policy, 'scopeForUser')` probe treats
 * "the bound policy is some other class" as indistinguishable from "the cascade is not installed", and
 * resolves both to *allow*. Beam hard-requires `rushing/laravel-permission-cascade`
 * (`composer.json:15`), so {@see BaseModelPolicy} is always loadable here and `instanceof` is available.
 * A host that binds a policy of its own that is NOT a cascade policy now gets no rows rather than every
 * row — deliberately, per ruling 1.
 *
 * ## What this does NOT do
 *
 * It does not decide whether a resource NEEDS a row guard. That question has three inputs this class
 * cannot see — whether the resource is `filterable`, whether it is mounted standalone or through a
 * relative (a relative lists through an already-authorized parent and is safe), and whether its rows
 * have an owner at all (`beam_steering_profiles` has no owner column; a site-global catalog needs no
 * filter and adding one would be cargo-cult). Those live on the resource and on the mount, and the
 * audit that would report them is deliberately not built yet: `particle-operation-surface` 15 already
 * claims two read-gating audits over this branch, and `api-surface-coherence` 124 is awaiting a human
 * ruling on how a registry-reading audit should behave over an empty registry.
 */
class RowAuthorization
{
    /**
     * Narrow `$query` to the rows the current actor may read, per the model's bound cascade policy.
     *
     * Fails closed: an unbound policy, or a bound policy that is not a cascade policy, yields no rows.
     *
     * @param  class-string  $modelClass  the model whose policy governs — usually a resource's `backing`
     */
    public static function apply(Builder $query, string $modelClass): Builder
    {
        $policy = Gate::getPolicyFor($modelClass);

        if (! $policy instanceof BaseModelPolicy) {
            return $query->whereRaw('1 = 0');
        }

        return $policy->scopeForUser($query, Auth::user());
    }

    /**
     * Whether a row guard is RESOLVABLE for this model — i.e. whether {@see apply()} would filter by the
     * cascade rather than fail closed.
     *
     * For diagnostics and audits, never as a branch around `apply()`: "no policy is bound" is the case
     * `apply()` exists to answer safely, so skipping it on this predicate reintroduces the fail-open bug
     * this class was written to remove.
     */
    public static function resolvable(string $modelClass): bool
    {
        return Gate::getPolicyFor($modelClass) instanceof BaseModelPolicy;
    }
}
