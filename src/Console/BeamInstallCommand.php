<?php

declare(strict_types=1);

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * `beam:install` — the ONE command that sets up the whole beam stack (beam-write-pipeline ticket 08).
 * It iterates the {@see BeamInstallManifest} core-first, runs each registered package's `vendor:publish`
 * tags, then migrates once. An operator runs this instead of a per-package installer for every beam
 * module, and a beam-* package joins simply by registering its step from its own provider — this command
 * never names a consumer.
 */
final class BeamInstallCommand extends Command
{
    protected $signature = 'beam:install {--force : Overwrite any already-published files}';

    protected $description = 'Publish + migrate the whole beam stack (core-first) from the self-registration manifest.';

    public function handle(BeamInstallManifest $manifest): int
    {
        $steps = $manifest->steps();

        if ($steps === []) {
            $this->warn('beam:install — nothing registered in the manifest.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        foreach ($steps as $step) {
            $this->line("beam:install → {$step->package}");

            foreach ($step->publishTags as $tag) {
                $this->callSilent('vendor:publish', array_merge(
                    ['--tag' => $tag],
                    $force ? ['--force' => true] : [],
                ));
            }
        }

        if ($manifest->migrates()) {
            $this->call('migrate', $force ? ['--force' => true] : []);
        }

        $this->info('beam stack installed.');

        return self::SUCCESS;
    }
}
