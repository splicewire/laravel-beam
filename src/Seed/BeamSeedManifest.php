<?php

namespace Splicewire\Beam\Seed;

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
class BeamSeedManifest
{
    /** @var list<SeedStep> */
    private array $steps = [];

    /**
     * Register a package's seed step. Idempotent per package name — re-registering replaces, so a provider
     * that boots twice (test harness) doesn't double-seed.
     *
     * @param  class-string  $seederClass  the seeder run via `db:seed --class`
     * @param  int  $order  lower runs first; beam-core registers at 0 (core-first), consumers default to 100
     * @param  ?string  $configGate  a config key that must be truthy to run this step; null ⇒ always runs
     */
    public function register(string $package, string $seederClass, int $order = 100, ?string $configGate = null): void
    {
        $this->steps = array_values(array_filter(
            $this->steps,
            static fn (SeedStep $step): bool => $step->package !== $package,
        ));

        $this->steps[] = new SeedStep($package, $seederClass, $order, $configGate);
    }

    /**
     * All registered steps, ordered core-first (ascending {@see SeedStep::$order}, ties keeping registration
     * order — `usort` has been stable since PHP 8.0).
     *
     * @return list<SeedStep>
     */
    public function steps(): array
    {
        $steps = $this->steps;
        usort($steps, static fn (SeedStep $a, SeedStep $b): int => $a->order <=> $b->order);

        return $steps;
    }
}
