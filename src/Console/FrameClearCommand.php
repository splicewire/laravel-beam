<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Frame\FrameResourceManifest;

/**
 * `php artisan beam:frame-clear` — remove the cached `#[AdminResource]` manifest so boot falls back
 * to a live filesystem scan again (dev). Also invoked by the `optimize:clear` hook wired in the
 * provider, mirroring how Laravel's own caches clear.
 */
class FrameClearCommand extends Command
{
    protected $signature = 'beam:frame-clear';

    protected $description = 'Clear the cached #[AdminResource] class manifest (boot falls back to a live scan).';

    public function handle(FrameResourceManifest $manifest): int
    {
        $manifest->clear();

        $this->components->info('Cleared the frame resource manifest cache.');

        return self::SUCCESS;
    }
}
