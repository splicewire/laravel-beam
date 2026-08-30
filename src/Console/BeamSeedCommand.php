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
 * Per-seeder failures are NON-FATAL to the RUN and FATAL to the EXIT CODE, and the distinction is the whole
 * point. A seeder that throws is reported and the run continues — one brittle seeder never aborts the whole
 * seed — but the command ends with a summary and returns {@see self::FAILURE}.
 *
 * ⚠️ **It returned SUCCESS unconditionally until beam-docs-satellite ticket 46 / beam-facade 143.** The last
 * line was always the unqualified `beam stack seeded.`, failures were `warn()`ed mid-stream where a long
 * install scrolls them away, and nothing automated could tell a clean seed from a broken one. Three real
 * key-type defects (`~/Herd/beam` `model_has_roles.model_id` varchar, `~/Herd/satellite` `users.id` bigint,
 * `~/Herd/tower` `beam_teams.user_id` bigint) sat behind `↳ … failed (continuing)` inside runs that returned
 * success, and were found by a human reading scrollback, not by any gate.
 *
 * The old docblock justified the swallow with "the accounts role step in a starter that runs
 * `register_auth_migrations=false`". That case is real and is **already expressed as a config gate** —
 * {@see SeedStep::$configGate}, e.g. `beam.accounts.demo.seed_users` — which is skipped cleanly and reported
 * as a skip. An EXCEPTION is therefore not an expected condition on this path; it is a defect, and the exit
 * code now says so. `--tolerate-failures` restores the old exit-0 behaviour for a caller that genuinely wants
 * best-effort.
 */
class BeamSeedCommand extends Command
{
    use AsSystemWriter;

    protected $signature = 'splicewire:beam:seed
        {--force : Run seeders even in production (passed through to db:seed)}
        {--tolerate-failures : Exit 0 even when a seeder failed (report only — the pre-ticket-46 behaviour)}';

    protected $description = 'Seed the whole beam stack from the self-registration manifest (core-first), each seeder config-gated.';

    /** @var list<array{seeder: string, package: string, message: string}> */
    private array $failures = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $seeded = [];

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

        return $this->report();
    }

    /**
     * The end-of-run summary, and the reason this command has an exit code worth reading.
     *
     * Printed AFTER every step so it survives the scrollback of a long `splicewire:beam:install`, which is
     * where these failures were being lost: a `warn()` fifty lines up, followed by a cheerful terminal line.
     */
    private function report(): int
    {
        $this->newLine();
        $this->line(sprintf(
            'splicewire:beam:seed — %d seeded, %d skipped (gate off), %d FAILED.',
            count($this->seeded),
            count($this->skipped),
            count($this->failures),
        ));

        if ($this->failures === []) {
            $this->info('beam stack seeded.');

            return self::SUCCESS;
        }

        foreach ($this->failures as $failure) {
            $this->error("  ✗ {$failure['seeder']} ({$failure['package']}): {$failure['message']}");
        }

        // A failed seeder is a DEFECT, not a supported outcome — the supported way to not run a seeder is its
        // config gate. Exit non-zero so a script, a CI job or an installer can tell the difference; the data
        // that DID seed is left in place, because a partial seed is still worth more than a rollback here.
        if ($this->option('tolerate-failures')) {
            $this->warn('beam stack seeded WITH FAILURES — exit code suppressed by --tolerate-failures.');

            return self::SUCCESS;
        }

        $this->error('beam stack seeding FAILED — see the seeders above. (--tolerate-failures to exit 0 anyway.)');

        return self::FAILURE;
    }

    private function runStep(SeedStep $step): void
    {
        if ($step->configGate !== null && ! config($step->configGate)) {
            $this->line("splicewire:beam:seed → {$step->package}: skipped ({$step->configGate} is off)");
            $this->skipped[] = $step->seeder;

            return;
        }

        $this->line("splicewire:beam:seed → {$step->package}: {$step->seeder}");

        try {
            // db:seed reports its own failures through the exit code as well as by throwing, and a non-zero
            // return here is NOT an exception — read it, or a seeder that fails politely is recorded as a
            // success. This is the same defect one layer down that this command had at its own top level.
            $code = $this->call('db:seed', [
                '--class' => $step->seeder,
                '--force' => true,
            ]);

            if ($code !== self::SUCCESS) {
                $this->failures[] = [
                    'seeder' => $step->seeder,
                    'package' => $step->package,
                    'message' => "db:seed exited {$code}",
                ];
                $this->warn("  ↳ {$step->seeder} exited {$code} (continuing).");

                return;
            }

            $this->seeded[] = $step->seeder;
        } catch (\Throwable $e) {
            // Still non-fatal to the RUN — one brittle seeder never aborts the whole seed — but recorded, so
            // the exit code and the summary both tell the truth.
            $this->failures[] = [
                'seeder' => $step->seeder,
                'package' => $step->package,
                'message' => $e->getMessage(),
            ];
            $this->warn("  ↳ {$step->seeder} failed (continuing): {$e->getMessage()}");
        }
    }
}
