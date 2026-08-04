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
 *   - `schemas:generate`                 — versioned `#[SchemaIdentity]` Data → JSON schema artifacts (schemastud)
 *   - `splicewire:beam:generate-client`  — the route manifest → route map + typed hooks (this package)
 *
 * The list is a **seam**: everything upstream registers into the same layer by appending to the
 * `beam.client.assets.generators` config, so "generate everything" stays one command as the stack grows —
 * rather than each new generator having to teach every caller about itself. A generator that isn't
 * installed in this host is skipped with a note, never a hard failure.
 */
class GenerateAssetsCommand extends Command
{
    protected $signature = 'splicewire:beam:generate-assets';

    protected $description = 'Generate every committed BE→FE contract artifact: TypeScript types, JSON schemas, and the route/hook client';

    /** The default pipeline, in dependency order (shapes → schemas → the client that references both). */
    private const DEFAULT_GENERATORS = [
        'typescript:transform',
        'schemas:generate',
        'splicewire:beam:generate-client',
    ];

    public function handle(): int
    {
        $generators = config('beam.client.assets.generators', self::DEFAULT_GENERATORS);
        $failed = false;

        foreach ($generators as $command) {
            if (! $this->getApplication()->has($command)) {
                $this->components->warn("Skipping '{$command}' — not registered in this host.");

                continue;
            }

            $this->components->task($command, function () use ($command, &$failed): bool {
                $ok = $this->call($command) === self::SUCCESS;
                $failed = $failed || ! $ok;

                return $ok;
            });
        }

        $this->newLine();

        if ($failed) {
            $this->components->error('One or more generators failed — the contract artifacts may be incomplete.');

            return self::FAILURE;
        }

        $this->components->info('All contract artifacts regenerated.');

        return self::SUCCESS;
    }
}
