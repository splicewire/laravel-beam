<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Seed\SeedStep;
use Splicewire\Beam\Write\AsSystemWriter;

/**
 * `splicewire:beam:seed` — the ONE command that seeds the whole beam stack's package-owned data.
 *
 * The seed-side twin of {@see BeamInstallCommand}: it iterates the {@see BeamSeedManifest} core-first, and a
 * beam-* package joins by registering its own {@see SeedStep} from its own provider, so this command NEVER
 * names a consumer. A host's `DatabaseSeeder` calls this once instead of hand-calling `DemoTeamSeeder` and
 * friends by class.
 *
 * Each step may carry a config gate — skipped (and reported as skipped) when `config($gate)` is falsy, so a
 * demo-only seeder registers unconditionally yet fires only where its gate is on (e.g. non-production).
 *
 * Per-seeder failures are NON-FATAL: a seeder that throws (e.g. the accounts role step in a starter that runs
 * `register_auth_migrations=false`) is reported and the run continues — one brittle seeder never aborts the
 * whole seed. Mirrors how {@see BeamInstallCommand} reports each step.
 */
class BeamSeedCommand extends Command
{
    use AsSystemWriter;

    protected $signature = 'splicewire:beam:seed {--force : Run seeders even in production (passed through to db:seed)}';

    protected $description = 'Seed the whole beam stack from the self-registration manifest (core-first), each seeder config-gated.';

    public function handle(BeamSeedManifest $manifest): int
    {
        $steps = $manifest->steps();

        if ($steps === []) {
            $this->warn('splicewire:beam:seed — nothing registered in the manifest.');

            return self::SUCCESS;
        }

        $this->asSystemWriter(function () use ($steps): void {
            foreach ($steps as $step) {
                $this->runStep($step);
            }
        });

        $this->info('beam stack seeded.');

        return self::SUCCESS;
    }

    private function runStep(SeedStep $step): void
    {
        if ($step->configGate !== null && ! config($step->configGate)) {
            $this->line("splicewire:beam:seed → {$step->package}: skipped ({$step->configGate} is off)");

            return;
        }

        $this->line("splicewire:beam:seed → {$step->package}: {$step->seeder}");

        try {
            $this->call('db:seed', [
                '--class' => $step->seeder,
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: report and carry on so one brittle seeder never aborts the whole seed.
            $this->warn("  ↳ {$step->seeder} failed (continuing): {$e->getMessage()}");
        }
    }
}
