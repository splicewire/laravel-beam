<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\Client\LangFingerprint;

/**
 * Removes the translation content fingerprint artifact: the command `optimize:clear` runs (localization 46).
 *
 * Laravel's `optimize:clear` calls each registered clear task as a command NAME, so the task cannot carry a flag:
 * registering `beam:lang:fingerprint --clear` failed on every host ("Command ... is not defined"). This command is that
 * task with no flag. `beam:lang:fingerprint --clear` stays, the same operation for anyone who calls it directly (deploy
 * scripts, docs, muscle memory); both run {@see LangFingerprint::clearArtifact()}.
 */
final class LangFingerprintClearCommand extends Command
{
    protected $signature = 'beam:lang:fingerprint-clear';

    protected $description = 'Remove the translation content fingerprint artifact (what optimize:clear runs; the same as beam:lang:fingerprint --clear).';

    public function handle(): int
    {
        LangFingerprint::clearArtifact();
        $this->info('Cleared '.LangFingerprint::artifactPath().'.');

        return self::SUCCESS;
    }
}
