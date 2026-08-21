<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Access\Gate;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Seed\SeedStep;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\GateWriteGate;
use Splicewire\Beam\Write\PermissiveWriteGate;

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

    /**
     * Run the whole seed pass under the SERVER-TRUSTED {@see PermissiveWriteGate}, restoring the host's
     * own binding afterwards.
     *
     * ## Why a seed cannot go through the default gate
     *
     * {@see GateWriteGate} is deny-by-default: it delegates to Laravel's authorization gate, which refuses
     * any ability with no matching policy, for any actor — and a console seeder has no actor at all. No
     * package or host in the estate declares a `BeamParticle` policy, so **every** seeder that writes a
     * particle body was refused with `The write gate refused a write to [Splicewire\Beam\Models\BeamParticle]`.
     * That is not a misconfiguration to fix per-host: a fresh install has no policies by construction, and
     * `splicewire:beam:seed` provisioning the realm root and the docs subtree is the first thing it runs.
     *
     * Found on `splicewire/www` (beam-docs-satellite ticket 07) rather than in any suite, because a package
     * test binds its own gate and a testbench host has no policies to disagree with — the same shape as the
     * artifact-path and boundary defects the same map found: an OTB promise is only proven on a real host.
     *
     * ## Why permissive is correct here rather than a loosened default
     *
     * {@see PermissiveWriteGate} exists for exactly this — "a server flow whose caller has ALREADY performed
     * authorization". An operator at a shell running `artisan` IS that authorization; there is no request,
     * no actor to check, and nothing left for a policy to decide. The deny-by-default binding is untouched
     * for every HTTP path, which is where it earns its keep. The swap is scoped to this command's run and
     * reverted in a `finally`, so a seeder that throws cannot leave a permissive gate bound.
     */
    private function asSystemWriter(callable $work): void
    {
        $app = $this->laravel;
        $previous = $app->getBindings()[WriteGate::class] ?? null;

        $app->bind(WriteGate::class, fn () => new PermissiveWriteGate);

        try {
            $work();
        } finally {
            if ($previous === null) {
                $app->bind(WriteGate::class, fn ($a) => new GateWriteGate($a->make(Gate::class)));
            } else {
                // Restore the host's own binding verbatim — including its `shared` flag — rather than
                // re-asserting beam's default over the top of a host that deliberately bound something else.
                $app->bind(WriteGate::class, $previous['concrete'], $previous['shared'] ?? false);
            }
        }
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
