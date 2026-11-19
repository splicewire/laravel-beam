<?php

namespace Splicewire\Beam\Authorization;

use Closure;
use Illuminate\Auth\Access\Gate as LaravelGate;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Rushing\PermissionCascade\Support\CredentialScope;

/**
 * A scoped credential narrows every Gate check of the principal acting with it, not only the checks that reach the
 * permission cascade (ADR-0109; standwell gap-assessment-build 36 and 39).
 *
 * Before this, the scope applied in two places: the cascade's `BaseModelPolicy` (bare verbs on cascade-backed models)
 * and beam's {@see AbilityResolver} (particle op gates and frame affordances). Every other check answered as if the
 * token were the user: a flat permission name asked by a controller (`Gate::authorize('schemas.manage')`), a `can:`
 * route middleware, a policy outside the cascade that asks a permission (`$user->can('entity-evidence.write')`), and
 * any check a host's allow-all `Gate::before` (the flagship's Root) answered first. This class closes those, in two
 * parts:
 *
 *  1. **{@see before()}**, registered by beam as a `Gate::before` the moment the Gate is first resolved, so it runs
 *     ahead of spatie's permission `before` and a host's own. For the acting principal under a scoped credential, a
 *     check whose ability is a flat permission name (`declarations.check`, `schemas.manage`, `tenant:provision`) is
 *     refused unless the scope carries that name; otherwise it says nothing and the check proceeds. Because a policy
 *     outside the cascade decides through such names, its verbs are narrowed through the names it asks.
 *  2. **{@see superuser()}**, for a host's allow-all `Gate::before`. A superuser grant answers before any policy
 *     runs, so under a scope it must not answer `true` blindly: it answers a flat name by the scope, and a bare verb
 *     by the verb's own definition evaluated without the before callbacks (whose permission checks are narrowed by
 *     (1) and granted to the superuser by this method), or by the scope carrying the verb's derived permission name
 *     (`hook.reset`, `hook.12.reset`), the cascade's naming. A superuser grant written inside a policy (a policy
 *     `before`, an `isRoot()` arm) uses {@see superuserMay()}, the derived-name half, because re-evaluating the
 *     definition there would recurse.
 *
 * Unchanged, on purpose (each also stated in the particle doctrine's inventory):
 *
 *  - the ENTITLEMENT plane (`entitlement:{key}`): a plan feature of the tenant, not a principal permission
 *    (ADR-0109 decision 1, owner 2026-11-18). Under a scope it is answered principal-only, permissions its
 *    definition asks included ({@see principalOnly()});
 *  - an unscoped call: a session, a `['*']` token, an unknown token, no bound resolver;
 *  - a guest, and a check made for anyone but the request's authenticated principal (a recipient, another member):
 *    the scope belongs to the credential that authenticated THIS request;
 *  - a decision that asks no permission at all (membership, ownership, a closure that returns a constant): there is
 *    no permission name in a token's vocabulary to narrow it by. Those sites are listed, not narrowed.
 *
 * It can only subtract: every answer here is `false`, "no say", the chain's own principal-only answer (an
 * entitlement), or {@see superuser()}, which is the host's grant itself, made narrower.
 */
class CredentialScopeGate
{
    /**
     * How deep the current evaluation is inside an entitlement check answered principal-only ({@see before()}). While
     * it is above zero no scope governs any check, so an entitlement definition that asks a permission internally
     * (`entitlement:analytics.view` => `$user->can('analytics.view')`) keeps its principal answer.
     */
    protected static int $principalOnly = 0;

    public function __construct(protected Gate $gate) {}

    /**
     * Whether a narrowing credential scope governs checks made for `$user`: a resolver is bound, the request's
     * credential is scoped, and `$user` is the request's authenticated principal.
     */
    public function scopes(mixed $user): bool
    {
        if ($user === null
            || static::$principalOnly > 0
            || ! app()->bound(CredentialScopeResolver::class)
            || ! app(CredentialScope::class)->isScoped()) {
            return false;
        }

        return $this->isActingPrincipal($user);
    }

    /** Whether the acting credential's scope carries `$permission` (unscoped: always). */
    public function permits(string $permission): bool
    {
        return ! app()->bound(CredentialScopeResolver::class) || app(CredentialScope::class)->permits($permission);
    }

    /**
     * The `Gate::before` body: a flat permission name the scope does not carry is refused for the acting principal;
     * every other check is left to the rest of the chain (`null`).
     *
     * An ENTITLEMENT check under a scope is answered here, by the full chain evaluated principal-only: the plane is
     * exempt, and an entitlement definition may ask a permission internally (the flagship's `entitlement:analytics.view`
     * asks `analytics.view`), which this before would otherwise refuse and so change the entitlement's answer. The same
     * permission asked directly stays narrowed.
     *
     * @param  array<int, mixed>|mixed  $arguments
     */
    public function before(mixed $user, mixed $ability, mixed $arguments = []): ?bool
    {
        if (! is_string($ability) || ! $this->scopes($user)) {
            return null;
        }

        if (str_starts_with($ability, AbilityResolver::ENTITLEMENT_PREFIX)) {
            return $this->principalOnly(fn (): bool => $this->gate->forUser($user)
                ->allows($ability, is_array($arguments) ? $arguments : [$arguments]));
        }

        if (! self::isPermissionName($ability)) {
            return null;
        }

        return $this->permits($ability) ? null : false;
    }

    /**
     * Run `$check` with no scope governing any check it makes (see {@see $principalOnly}).
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     */
    public function principalOnly(Closure $check): mixed
    {
        static::$principalOnly++;

        try {
            return $check();
        } finally {
            static::$principalOnly--;
        }
    }

    /**
     * The answer a host's allow-all (superuser) `Gate::before` gives for `$user`: `true` when no scope governs the
     * check, as before. Under a scope: the entitlement plane `true`; a flat name by the scope; a bare verb when its own
     * definition allows it without the before callbacks, or the scope carries its derived permission name.
     *
     * @param  array<int, mixed>  $arguments  the check's arguments, as a `Gate::before` receives them
     */
    public function superuser(mixed $user, mixed $ability, array $arguments = []): bool
    {
        if (! is_string($ability)) {
            return true;
        }

        if ($this->superuserMay($user, $ability, $arguments[0] ?? null)) {
            return true;
        }

        return ! self::isPermissionName($ability) && $this->definitionAllows($user, $ability, $arguments);
    }

    /**
     * The narrower half of {@see superuser()}, for a superuser grant made INSIDE a policy (a policy `before`, an
     * `isRoot()` arm), where evaluating the definition again would recurse: `true` when no scope governs the check;
     * under a scope, a flat name by the scope and a bare verb by its derived permission name only.
     */
    public function superuserMay(mixed $user, string $ability, mixed $subject = null): bool
    {
        if (str_starts_with($ability, AbilityResolver::ENTITLEMENT_PREFIX) || ! $this->scopes($user)) {
            return true;
        }

        return self::isPermissionName($ability) ? $this->permits($ability) : $this->permitsVerb($ability, $subject);
    }

    /**
     * Whether the scope carries a bare verb's derived permission name: `{model}.{verb}` for a model class or instance,
     * `{model}.{id}.{verb}` for a persisted instance, and the verb itself when there is no model subject.
     */
    public function permitsVerb(string $verb, mixed $subject = null): bool
    {
        $names = [];

        if ($subject instanceof Model) {
            $names[] = PermissionNamer::assemble(get_class($subject), $verb);

            if ($subject->exists) {
                $names[] = PermissionNamer::assemble($subject, $verb);
            }
        } elseif (is_string($subject) && is_subclass_of($subject, Model::class)) {
            $names[] = PermissionNamer::assemble($subject, $verb);
        } else {
            $names[] = $verb;
        }

        foreach ($names as $name) {
            if ($this->permits($name)) {
                return true;
            }
        }

        return false;
    }

    /** A flat namespaced name (`declarations.check`, `tenant:provision`), not a bare verb or an entitlement key. */
    public static function isPermissionName(string $ability): bool
    {
        return ! str_starts_with($ability, AbilityResolver::ENTITLEMENT_PREFIX)
            && (str_contains($ability, '.') || str_contains($ability, ':'));
    }

    /**
     * The check answered by its own definition (policy method, policy `before`, `Gate::define` callback) with every
     * `Gate::before` removed, so a superuser grant cannot answer it. Checks the definition makes in turn go through
     * the full Gate, where {@see before()} narrows them and the host's superuser `before` answers them here.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function definitionAllows(mixed $user, string $ability, array $arguments): bool
    {
        if (! $this->gate instanceof LaravelGate) {
            return false;
        }

        $bare = $this->gate->forUser($user);
        Closure::bind(fn () => $this->beforeCallbacks = [], $bare, LaravelGate::class)();

        return $bare->allows($ability, $arguments);
    }

    /**
     * Whether `$user` is a principal this request authenticated: the user any already-resolved guard holds (a named
     * guard such as `sanctum` included, so `Gate::forUser(auth('sanctum')->user())` is narrowed while the default
     * guard is empty or holds someone else), or the default request user. Same object, or the same auth identifier
     * (a tenant `TenantUser` mirror shares its central user's). Any other user is not narrowed.
     */
    protected function isActingPrincipal(mixed $user): bool
    {
        foreach ($this->resolvedGuardUsers() as $acting) {
            if ($this->samePrincipal($acting, $user)) {
                return true;
            }
        }

        return $this->samePrincipal(request()?->user(), $user);
    }

    /** @return list<mixed> the users the auth manager's already-resolved guards hold, resolving no new guard */
    protected function resolvedGuardUsers(): array
    {
        $auth = app('auth');

        if (! $auth instanceof AuthManager) {
            return [];
        }

        $guards = Closure::bind(fn (): array => $this->guards, $auth, AuthManager::class)();
        $users = [];

        foreach ($guards as $guard) {
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                $users[] = $guard->user();
            }
        }

        return $users;
    }

    protected function samePrincipal(mixed $acting, mixed $user): bool
    {
        if ($acting === null) {
            return false;
        }

        if ($acting === $user) {
            return true;
        }

        if (! $acting instanceof Authenticatable || ! $user instanceof Authenticatable) {
            return false;
        }

        $actingId = $acting->getAuthIdentifier();

        return $actingId !== null && (string) $actingId === (string) $user->getAuthIdentifier();
    }
}
