<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm;

use InvalidArgumentException;
use Schemastud\Frame\Realm\RealmDefinition;

/**
 * The beam realm kit (ADR-0156): the concrete {@see RealmDefinition} instances the manifest builder and
 * the SPA route generator read, plus the seam packages contribute more through. The realm *shape*
 * (`RealmDefinition`) stays in the agnostic foundation `schemastud/laravel-frame`; the
 * concrete kit lives here in beam (legal now that `beam → frame`), so a self-hosted / satellite install
 * selects its realms from the package rather than hand-defining them.
 *
 * Ships three BASE realms every install has:
 *  - **`admin`**  — the operator console: central, never-tenanted, root-guarded, mounted at `/admin`.
 *  - **`tenant`** — the workspace realm: mounted at `/`, its own auth. Whether it resolves a tenant on
 *    this deployment is the `TenantResolver`'s call (ticket 08 slice B), not a flag here.
 *  - **`user`**   — the account/identity realm: the authenticated user across workspaces, mounted at
 *    `/settings`. Composes THROUGH the tenant realm when collapsed (`stack: ['tenant']`) — a
 *    single-tenant install resolves a user's Settings within their workspace; a future cross-workspace
 *    SaaS drops the stack so `user` sources its own account manifest.
 *
 * A capability package CONTRIBUTES further realms via {@see register()} — e.g.
 * `laravel-satellite-multi-tenancy` re-registers a differently-shaped `tenant` realm. Contribution is
 * last-wins by key here.
 *
 * **Composition (ticket 08 slice E).** A realm's effective manifest source is the topmost resolvable
 * realm in its `stack` ({@see effective()}) — a general stack-walk that replaced the retired
 * user↔tenant `collapses()`/`effective()` special-case. The `user` realm's stack is config-driven
 * (`frame.collapse_user_realm`): an app may declare it with or without the tenant in its stack.
 */
class RealmRegistry
{
    /** @var array<string, RealmDefinition> */
    private array $realms = [];

    public function __construct()
    {
        $this->register($this->operator());
        $this->register($this->tenant());
        $this->register($this->user());
    }

    /**
     * The operator (admin) console — the central, never-tenanted Root realm mounted under `/admin`.
     * The concept is "operator"; the wire key stays `admin`.
     */
    public function operator(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'admin',
            routeBase: '/admin',
            guard: 'root',
            central: true,
        );
    }

    /**
     * The tenant workspace realm — mounted at the root, tenant-scoped, its own auth. Whether it actually
     * resolves a tenant on this deployment is the TenantResolver's call (ticket 08), not a flag here.
     */
    public function tenant(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'tenant',
            routeBase: '/',
            guard: null,
            central: false,
        );
    }

    /**
     * The account/identity realm — the authenticated user across workspaces, mounted at `/settings` with
     * the shell's own auth. On a single-tenant instance the user's account is scoped to their workspace,
     * so the realm COMPOSES THROUGH the tenant realm (`stack: ['tenant']`) and its Settings surfaces
     * resolve there — exactly as before the split existed. A future cross-workspace SaaS flips
     * `frame.collapse_user_realm` off; the stack empties and `user` sources its own account manifest.
     */
    public function user(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'user',
            routeBase: '/settings',
            guard: null,
            central: false,
            stack: $this->collapsesUserRealm() ? ['tenant'] : [],
        );
    }

    /** Contribute (or, last-wins, replace) a realm by key — the seam a capability package registers through. */
    public function register(RealmDefinition $realm): void
    {
        $this->realms[$realm->key] = $realm;
    }

    public function get(string $key): ?RealmDefinition
    {
        return $this->realms[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->realms[$key]);
    }

    /** @return array<string, RealmDefinition> */
    public function all(): array
    {
        return $this->realms;
    }

    /**
     * Resolve a realm by its wire key. An unknown key is a boot/routing bug — fail loud.
     */
    public function resolve(string $key): RealmDefinition
    {
        return $this->realms[$key]
            ?? throw new InvalidArgumentException("Unknown realm [{$key}] — no RealmDefinition registered.");
    }

    /**
     * The realm a realm's manifest/resources are effectively sourced FROM: the TOPMOST resolvable realm
     * in its `stack` (the last entry that resolves), or the realm ITSELF when the stack is empty. A general
     * stack-walk (ticket 08 slice E) — for the collapsed `user` realm (`stack: ['tenant']`) this returns
     * the tenant realm, so a host reads the Settings meta-area off the tenant manifest exactly as before
     * the collapse special-case existed; for a stackless realm (`admin`, or a separated `user`) it returns
     * itself.
     */
    public function effective(string $key): RealmDefinition
    {
        $realm = $this->resolve($key);
        $source = $realm;

        foreach ($realm->stack as $through) {
            if ($this->has($through)) {
                $source = $this->resolve($through);
            }
        }

        return $source;
    }

    /**
     * Whether the `user` realm composes through the `tenant` realm on this instance (the config sibling
     * of tenancy). True today: a user's account/settings live inside their workspace, so `user` stacks on
     * `tenant`. A future cross-workspace SaaS flips this off and the user realm sources its own manifest.
     */
    private function collapsesUserRealm(): bool
    {
        return (bool) config('frame.collapse_user_realm', true);
    }
}
