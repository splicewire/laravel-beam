<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm;

use Schemastud\Frame\Realm\RealmDefinition;

/**
 * The beam realm kit (ADR-0156): the concrete {@see RealmDefinition} instances the manifest builder and
 * the SPA route generator read, plus the seam packages contribute more through. The realm *shape*
 * (`RealmDefinition`) stays in the agnostic foundation `schemastud/laravel-frame`; the
 * concrete kit lives here in beam (legal now that `beam → frame`), so a self-hosted / satellite install
 * selects its realms from the package rather than hand-defining them.
 *
 * Ships two BASE realms every install has:
 *  - **`admin`** — the operator console: central, never-tenanted, root-guarded, mounted at `/admin`.
 *  - **`user`**  — the account/identity realm: the authenticated user across workspaces, mounted at `/`.
 *
 * A capability package CONTRIBUTES further realms via {@see register()} — e.g.
 * `laravel-satellite-multi-tenancy` registers a `tenant` realm. Contribution is last-wins by key here;
 * the richer OVERRIDE semantics (a contributed realm refining a base realm's tenancy axis — "tenant
 * overrides admin") are owned by the `realm-architecture` effort, not this kit.
 */
class RealmRegistry
{
    /** @var array<string, RealmDefinition> */
    private array $realms = [];

    public function __construct()
    {
        $this->register($this->operator());
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
            tenancy: false,
        );
    }

    /**
     * The account/identity realm — the authenticated user across workspaces, mounted at the root with the
     * shell's own auth. On a single-tenant instance this is the whole app; a multi-tenant install layers a
     * `tenant` realm over it (contributed by the tenancy package).
     */
    public function user(): RealmDefinition
    {
        return new RealmDefinition(
            key: 'user',
            routeBase: '/',
            guard: null,
            central: false,
            tenancy: false,
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
}
