<?php

namespace Splicewire\Beam\Seed;

/**
 * One beam-* package's seed step in the {@see BeamSeedManifest} — the seed-side twin of
 * {@see \Splicewire\Beam\Install\InstallStep}: a `db:seed`-runnable seeder class, an ordering hint so
 * `splicewire:beam:seed` runs core-first then consumers, and an optional config gate a step is skipped
 * under (so a demo-only seeder never fires in production).
 */
class SeedStep
{
    /**
     * @param  string  $package  the registering package's name (for operator output only — beam never
     *                           branches on it; a consumer names ITSELF)
     * @param  class-string  $seeder  the seeder class run via `db:seed --class`
     * @param  int  $order  lower runs first; beam-core registers at 0 (core-first), consumers default to 100
     * @param  ?string  $configGate  a config key that must be truthy for this step to run (`config($gate)`);
     *                               null ⇒ always runs. Lets a demo-only seeder register unconditionally yet
     *                               fire only where its gate (e.g. `beam.accounts.seed_demo_users`) is on.
     */
    public function __construct(
        public string $package,
        public string $seeder,
        public int $order = 100,
        public ?string $configGate = null,
    ) {}
}
