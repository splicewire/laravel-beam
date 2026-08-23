<?php

namespace Splicewire\Beam\Realm;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Realm\Attributes\Realm;

/**
 * The beam realm kit (ADR-0156): the concrete {@see RealmDefinition} instances the manifest builder and
 * the SPA route generator read, plus the seam packages contribute more through. The realm *shape*
 * (`RealmDefinition`) stays in the agnostic foundation `schemastud/laravel-frame`; the
 * concrete kit lives here in beam (legal now that `beam → frame`), so a self-hosted / satellite install
 * selects its realms from the package rather than hand-defining them.
 *
 * Ships three BASE realms every install has:
 *  - **`operator`** — the operator console: central, never-tenanted, root-guarded, mounted at `/operator`.
 *  - **`tenant`** — the workspace realm: mounted at `/`, its own auth. Whether it resolves a tenant on
 *    this deployment is the `TenantResolver`'s call (ticket 08 slice B), not a flag here.
 *  - **`user`**   — the account/identity realm: the authenticated user across workspaces, mounted at
 *    `/settings`. Composes THROUGH the tenant realm when collapsed (`stack: ['tenant']`) — a
 *    single-tenant install resolves a user's Settings within their workspace; a future cross-workspace
 *    SaaS drops the stack so `user` sources its own account manifest.
 *  - **`site`**   — the PUBLIC content realm (ADR-0165, beamux-entry-charter S3): rooted at the domain
 *    `/`, **unguarded by default** (`guard: null`). Public content is not an authenticated admin
 *    surface, so it is deliberately NOT the `tenant` realm — it is the root of a `BeamUxEntry`
 *    containment tree, from which the public URL of every entry is inherited (decoupled from the
 *    build-grouping `namespace`).
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
#[IsRegistry(
    root: 'beam.realm',
    of: 'authorization realms (admin·tenant·user·docs) governing resource access',
    arity: RegistryArity::PickOne,
    entryType: RealmDefinition::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Seeded-plus-registered: the constructor seeds the base realms AND #[Realm] classes augment the '
        .'same instance. Contribution is last-wins by key, and deliberately so — a capability package '
        .'re-registers a differently-shaped realm over the base one. `beam.realm.overlays` nests UNDER '
        .'this root; longest prefix routes them apart.',
    order: 14,
)]
class RealmRegistry
{
    /** @var array<string, RealmDefinition> */
    private array $realms = [];

    /**
     * Whether the config-driven `user` realm has been EXPLICITLY overridden since construction (by a
     * capability package or attributed-realm discovery calling {@see register('user', …)}). While false,
     * the `user` realm is re-derived from live config on read (its `stack` is `frame.collapse_user_realm`
     * driven, slice E) — so a runtime config flip re-collapses/separates it even though the registry is
     * now a boot singleton (slice D). Once overridden, the host's definition wins and is never re-synced.
     */
    private bool $userRealmOverridden = false;

    public function __construct()
    {
        $this->register($this->operator());
        $this->register($this->tenant());
        $this->register($this->site());
        $this->realms['user'] = $this->user();
    }

    /**
     * The operator console — the central, never-tenanted Root realm mounted under `/operator`.
     * Originally the concept was "operator" while the wire key and route stayed `admin` (a stability
     * call); ADR-0156's 2026-08-07 addendum supersedes that split — both now follow the concept.
     */
    public function operator(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'operator',
            routeBase: '/operator',
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

    /**
     * The PUBLIC content realm (ADR-0165, beamux-entry-charter S3) — the root of the public site's
     * `BeamUxEntry` containment tree, mounted at the domain root `/` and **unguarded by default**
     * (`guard: null`): public content is not an authenticated surface. Distinct from the `tenant` realm
     * (same route base, but that is the authenticated workspace); the two coexist by key. This is the
     * realm/sitemap root from which entry URLs are inherited, decoupled from the build-grouping
     * `namespace` (disk-only, S2). Not `central` — a site is per-deployment public content, not the
     * operator console.
     */
    public function site(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'site',
            routeBase: '/',
            guard: null,
            central: false,
        );
    }

    /** Contribute (or, last-wins, replace) a realm by key — the seam a capability package registers through. */
    public function register(RealmDefinition $realm): void
    {
        if ($realm->key === 'user') {
            $this->userRealmOverridden = true;
        }

        $this->realms[$realm->key] = $realm;
    }

    /**
     * Reflect a `#[Realm]`-family marker class (the generic `#[Realm]` or a preset subclass
     * `#[OperatorRealm]`/`#[UserRealm]`/`#[TenantRealm]`) and {@see register()} its projected
     * {@see RealmDefinition}. The explicit-registration path for attributed realms: the boot provider
     * hands the configured marker-class LIST here — realms are ~4, so a filesystem scan is overkill
     * (the retired `RealmDiscovery` did one). Additive/last-wins by key, exactly like `register()`.
     *
     * @param  class-string  $markerClass
     */
    public function registerClass(string $markerClass): void
    {
        if (! class_exists($markerClass)) {
            throw new InvalidArgumentException("Realm marker class [{$markerClass}] does not exist.");
        }

        $attrs = (new ReflectionClass($markerClass))->getAttributes(Realm::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attrs === []) {
            throw new InvalidArgumentException(
                "Class [{$markerClass}] is not annotated with #[Realm] (or a preset subclass); "
                .'use register() for attribute-less realms.'
            );
        }

        $this->register($attrs[0]->newInstance()->toDefinition());
    }

    public function get(string $key): ?RealmDefinition
    {
        $this->syncUserRealm();

        return $this->realms[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->syncUserRealm();

        return isset($this->realms[$key]);
    }

    /** @return array<string, RealmDefinition> */
    public function all(): array
    {
        $this->syncUserRealm();

        return $this->realms;
    }

    /**
     * Resolve a realm by its wire key. An unknown key is a boot/routing bug — fail loud.
     */
    public function resolve(string $key): RealmDefinition
    {
        $this->syncUserRealm();

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

    /**
     * Keep the stored `user` realm in step with live `frame.collapse_user_realm` config (slice E) — unless
     * a host has EXPLICITLY overridden it via {@see register()}. This preserves the pre-singleton (slice D)
     * behavior where each freshly-built registry re-read the config-driven stack, now that a single boot
     * instance is shared. A no-op once `user` is overridden or when the stored stack already matches.
     */
    private function syncUserRealm(): void
    {
        if ($this->userRealmOverridden) {
            return;
        }

        $expected = $this->user();

        if (! isset($this->realms['user']) || $this->realms['user']->stack !== $expected->stack) {
            $this->realms['user'] = $expected;
        }
    }
}
