<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;

/**
 * The umbrella asset generator (PROMOTED into laravel-beam) — one command for every committed
 * backend→frontend **contract artifact**, so a single `npm run generate` (or a CI step) rebuilds them all
 * and a dirty tree afterward is the drift signal. It composes the existing per-layer generators rather than
 * re-implementing them:
 *
 *   - `typescript:transform`             — `#[TypeScript]` Data classes → the TS types (Spatie)
 *   - `splicewire:beam:generate:contributed-types`
 *                                        — the contribution registry → each contributed-to resource's
 *                                          read type (owner Data class & its slices); this package
 *   - `schemas:generate`                 — versioned `#[SchemaIdentity]` Data → JSON schema artifacts (schemastud)
 *   - `splicewire:beam:generate:client`  — the route manifest → route map + typed hooks (this package)
 *
 * The list is a **seam**: everything upstream registers into the same layer by appending to the
 * `beam.client.assets.generators` config, so "generate everything" stays one command as the stack grows —
 * rather than each new generator having to teach every caller about itself. A generator that isn't
 * installed in this host is skipped with a note, never a hard failure — but the skip is REPORTABLE
 * (particle-doctrine-followups #13): `--json` emits a `{ran, skipped, failed}` summary, so a caller can
 * distinguish "this host legitimately lacks the generator" from "every generator ran clean". Silence is
 * no longer ambiguous.
 */
class GenerateAssetsCommand extends Command
{
    protected $signature = 'splicewire:beam:generate:assets
        {--json : Emit a machine-readable {ran, skipped, failed} summary (sub-command output suppressed)}';

    protected $description = 'Generate every committed BE→FE contract artifact: TypeScript types, JSON schemas, and the route/hook client';

    /** The default pipeline, in dependency order (shapes → schemas → the client that references both). */
    private const DEFAULT_GENERATORS = [
        'typescript:transform',
        // Derives from `typescript:transform`'s output and verifies against it, so it runs directly
        // after — the order is a dependency, not a preference (particle-contribution-seam #22).
        'splicewire:beam:generate:contributed-types',
        // The same emit-or-fail guarantee widened from contribution slices to the WHOLE declared particle
        // surface: every DTO a `#[ParticleResource]`/`#[ParticleOp]` names that also carries `#[TypeScript]`
        // must be in the tree `typescript:transform` just wrote. Writes nothing — a check that changed what
        // emits would silently retype the frontend.
        'splicewire:beam:verify:declared-types',
        'schemas:generate',
        'splicewire:beam:generate:client',
    ];

    public function handle(): int
    {
        $generators = config('beam.client.assets.generators', self::DEFAULT_GENERATORS);
        $json = (bool) $this->option('json');

        $ran = [];
        $skipped = [];
        $failed = [];

        foreach ($generators as $command) {
            if (! $this->getApplication()->has($command)) {
                $skipped[] = $command;

                if (! $json) {
                    $this->components->warn("Skipping '{$command}' — not registered in this host.");
                }

                continue;
            }

            if ($json) {
                if ($this->callSilently($command) === self::SUCCESS) {
                    $ran[] = $command;
                } else {
                    $failed[] = $command;
                }

                continue;
            }

            $this->components->task($command, function () use ($command, &$ran, &$failed): bool {
                $ok = $this->call($command) === self::SUCCESS;

                if ($ok) {
                    $ran[] = $command;
                } else {
                    $failed[] = $command;
                }

                return $ok;
            });
        }

        if ($json) {
            $this->output->writeln((string) json_encode([
                'ran' => $ran,
                'skipped' => $skipped,
                'failed' => $failed,
            ]));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();

        if ($failed !== []) {
            $this->components->error('One or more generators failed — the contract artifacts may be incomplete.');

            return self::FAILURE;
        }

        $this->components->info('All contract artifacts regenerated.');

        return self::SUCCESS;
    }
}
