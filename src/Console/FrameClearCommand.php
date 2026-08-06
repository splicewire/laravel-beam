<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Frame\FrameResourceManifest;

/**
 * `php artisan splicewire:beam:frame:clear` — remove the cached `#[ParticleResource]` manifest so boot falls back
 * to a live filesystem scan again (dev). Also invoked by the `optimize:clear` hook wired in the
 * provider, mirroring how Laravel's own caches clear.
 */
class FrameClearCommand extends Command
{
    protected $signature = 'splicewire:beam:frame:clear';

    protected $description = 'Clear the cached #[ParticleResource] class manifest (boot falls back to a live scan).';

    public function handle(FrameResourceManifest $manifest): int
    {
        $manifest->clear();

        $this->components->info('Cleared the frame resource manifest cache.');

        return self::SUCCESS;
    }
}
