<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Schema\SchemaSources;

/**
 * The boot-time audit scan-path contribution registry: a container singleton any package's
 * service provider pushes `(controllersDir, routesDir)` pairs into, so a family package's HTTP
 * surface joins the bypass/redundancy/house-style sweeps without the host app being edited —
 * the same self-registration pattern as {@see SchemaSources} et al.
 * beam-core never learns a contributor's name.
 *
 * The consuming audits ({@see ParticleControllerRedundancyAudit},
 * {@see ParticleOperationBypassAudit}, {@see HouseStyleAudit}) fold these dirs in ALONGSIDE the
 * host's own `app/Http/Controllers` + `routes/` — contributed paths extend the sweep, never
 * replace it.
 *
 * ## Honesty about reach
 * This is a BOOT-TIME seam: a package's paths are contributed by its own service provider, so
 * they join the sweep only in a host where that provider actually boots. A family package that
 * is NOT installed in the app under audit contributes nothing there — its surface is audited
 * only from hosts that compose it (or by its own package-local tooling). The seam widens
 * visibility to whatever the host composes; it is not a fleet-wide census.
 */
#[IsRegistry(
    root: 'beam.surgeon.scan-paths',
    of: 'package-contributed (controllersDir, routesDir) pairs joining the bypass/redundancy/house-style sweeps',
    arity: RegistryArity::RunAll,
    onDuplicate: OnDuplicate::Admit,
    note: 'Admit, not Supersede: state today is an unkeyed append-only list, so a package registering '
        .'twice contributes two rows and the sweep reads both. Migrating it to a keyed store would '
        .'CHANGE that behaviour, so the declaration records what it does rather than what it should do.',
    order: 11,
)]
/**
 * @implements Registry<array{package: string, controllersDir: string, routesDir: string}>
 */
class AuditScanPaths implements Registry
{
    /**
     * Registrations keyed by contributing package, in boot order.
     *
     * ⚠️ The key is a COMPOSER PACKAGE NAME — `splicewire/laravel-beam-commerce` — and `/` is not a
     * `Key` character, so a bare string throws. Registry-kernel 58 D5 rules this exact shape
     * (`BeamExtensionInstallManifest`, `BeamSeedManifest`, `BeamInstallManifest`):
     * {@see RelativeUriKey}, coordinate preserved as spelled, lossless both ways.
     *
     * `OnDuplicate::Admit` is unchanged and still load-bearing — the declaration's note says a package
     * registering twice contributes two rows and the sweep reads both, and `Admit` is what preserves
     * that under a keyed store.
     *
     * @var BasicRegistry<array{package: string, controllersDir: string, routesDir: string}>
     */
    protected BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Contribute a package's HTTP scan surface: the controllers dir its action classes live
     * under, and the routes dir that mounts them. A dir that does not exist (a package shipping
     * controllers but no route files, or vice versa) is fine — the audits' file walks treat an
     * absent dir as empty.
     *
     * ⚠️ RENAMED from `register()` by registry-kernel 38, and widening was not an option. The old
     * signature was THREE POSITIONAL STRINGS, so `$routesDir` would have landed in
     * {@see Registry::register()}'s `?string $by` slot — both `string`, so it type-checks, and the
     * value is silently lost. A rename fails LOUDLY at every call site instead, and the four live ones
     * (`beam-commerce`, `beam-market`, `beam-tenancy`, and this package's own suite) move with it.
     * Every parameter keeps its name, so named-argument call sites are unaffected.
     */
    public function registerScanPaths(string $package, string $controllersDir, string $routesDir): static
    {
        $this->store->register(RelativeUriKey::of($package), [
            'package' => $package,
            'controllersDir' => $controllersDir,
            'routesDir' => $routesDir,
        ], by: $package);

        return $this;
    }

    /** @return list<array{package: string, controllersDir: string, routesDir: string}> */
    public function paths(): array
    {
        return $this->store->matches($this->store->root());
    }

    /** @return list<string> the contributed controllers dirs, deduplicated, in registration order */
    public function controllersDirs(): array
    {
        return array_values(array_unique(array_column($this->paths(), 'controllersDir')));
    }

    /** @return list<string> the contributed routes dirs, deduplicated, in registration order */
    public function routesDirs(): array
    {
        return array_values(array_unique(array_column($this->paths(), 'routesDir')));
    }

    /* ---------------- Registry contract ---------------- */

    /** The kernel's door onto the same store {@see registerScanPaths()} writes through. */
    public function register(
        RegistryKey|string $key,
        mixed $entry = null,
        ?string $by = null,
        ?string $ability = null,
    ): static {
        $this->store->register($key instanceof RegistryKey ? $key : RelativeUriKey::of($key), $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key instanceof RegistryKey ? $key : RelativeUriKey::of($key));
    }

    /**
     * ⚠️ `Admit` is declared, so a package that registered twice — legal, and what the note above
     * records — makes this throw `AmbiguousRegistryMatch`. {@see paths()} is the read the audits use.
     */
    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key instanceof RegistryKey ? $key : RelativeUriKey::of($key));
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key instanceof RegistryKey ? $key : RelativeUriKey::of($key));
    }

    /** @return list<array{package: string, controllersDir: string, routesDir: string}> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key instanceof RegistryKey ? $key : RelativeUriKey::of($key));
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}
