<?php

namespace Splicewire\Beam\Seed;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The beam-seed self-registration manifest — the seed-side twin of {@see BeamInstallManifest}
 * (and {@see BeamDoctorManifest}). A container SINGLETON every beam-* package pushes its
 * own {@see SeedStep} into — from its OWN service provider — so a host's `DatabaseSeeder` runs the whole stack's
 * seed data from ONE command (`splicewire:beam:seed`) instead of hand-calling each package's seeder by class.
 *
 * The direction is load-bearing, exactly as on the install/doctor sides: consumers register DOWN into beam's
 * manifest; **beam-core never learns a consumer's name** (it just iterates whatever registered). That keeps the
 * dependency graph acyclic — beam depends on nothing above it, yet the whole family seeds together. Steps run
 * core-first (by {@see SeedStep::$order}, then registration order — `usort` is stable), so substrate rows land
 * before anything that composes them.
 *
 * Each step may carry a `$configGate` — a config key that must be truthy for the step to run — so a demo-only
 * seeder registers unconditionally yet fires only where its gate is on (e.g. non-production). The command reports
 * a gated skip rather than silently omitting it.
 */
#[IsRegistry(
    root: 'beam.seed.steps',
    of: 'per-package seed steps (seeder class + config gate) run by splicewire:beam:seed, core-first',
    arity: RegistryArity::RunAll,
    entryType: SeedStep::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'A step may carry a $configGate, so a registered step can legitimately not run. Emptiness and '
        .'skipping are different states; the command reports a gated skip rather than omitting it.',
    order: 3,
)]
/**
 * @implements Registry<SeedStep>
 */
class BeamSeedManifest implements Registry
{
    /** @var BasicRegistry<SeedStep> */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register a package's seed step. Idempotent per package name — re-registering replaces, so a provider
     * that boots twice (test harness) doesn't double-seed.
     *
     * @param  class-string  $seederClass  the seeder run via `db:seed --class`
     * @param  int  $order  lower runs first; beam-core registers at 0 (core-first), consumers default to 100
     * @param  ?string  $configGate  a config key that must be truthy to run this step; null ⇒ always runs
     */
    public function register(
        RegistryKey|string $package,
        mixed $seederClass = null,
        ?string $by = null,
        ?string $ability = null,
        int $order = 100,
        ?string $configGate = null,
    ): static {
        // ⚠️ The key is a COMPOSER PACKAGE NAME — `splicewire/laravel-beam-accounts` — and `/` is not a
        // `Key` character, so a bare string throws. Registry-kernel 58 D5 rules this shape for
        // `BeamExtensionInstallManifest`: `RelativeUriKey`, "composer coordinate preserved as
        // spelled." The slash is a joiner being translated, not a new grammar — every segment must
        // still satisfy `Key`'s own charset — and the translation is lossless both ways, which
        // matters because the same string is the coordinate a human reads back out of `beam:seed`.
        $this->store->register(
            $package instanceof RegistryKey ? $package : RelativeUriKey::of($package),
            new SeedStep((string) $package, (string) $seederClass, $order, $configGate),
            $by,
            $ability,
        );

        return $this;
    }

    /**
     * All registered steps, ordered core-first (ascending {@see SeedStep::$order}, ties keeping registration
     * order — `usort` has been stable since PHP 8.0).
     *
     * @return list<SeedStep>
     */
    public function steps(): array
    {
        $steps = array_values(array_map(
            fn (RegistryKey $key): mixed => $this->store->resolve($key),
            $this->store->keys(),
        ));

        usort($steps, static fn (SeedStep $a, SeedStep $b): int => $a->order <=> $b->order);

        return $steps;
    }

    /* ---------------- Registry contract ---------------- */

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return array<string, mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
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
