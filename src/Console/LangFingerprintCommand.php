<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\Client\LangFingerprint;

/**
 * Writes, or with `--clear` removes, the translation content fingerprint the client-catalog memo keys on (localization
 * 16; coord-lead ruling (a), 00:51Z). Hooked into `optimize` / `optimize:clear`. With no artifact, the fingerprint is
 * recomputed from contents on every call.
 */
final class LangFingerprintCommand extends Command
{
    protected $signature = 'beam:lang:fingerprint {--clear : remove the artifact (recompute from contents on every call)}';

    protected $description = 'Write the translation content fingerprint the client catalog memo keys on (or --clear it).';

    public function handle(): int
    {
        $path = LangFingerprint::artifactPath();
        if ($this->option('clear')) {
            if (is_file($path)) {
                unlink($path);
            }
            $this->info('Cleared '.$path.'.');

            return self::SUCCESS;
        }
        $fingerprint = LangFingerprint::computeTranslations();
        file_put_contents($path, '<?php return '.var_export(['translations' => $fingerprint], true).';'."\n");
        $this->info("Wrote {$path}: {$fingerprint}.");

        return self::SUCCESS;
    }
}
