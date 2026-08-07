<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Frame\FrameResourceManifest;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * `php artisan splicewire:beam:frame:cache` — build the cached `#[ParticleResource]` manifest.
 *
 * Mirrors Laravel's `package:discover` → `bootstrap/cache/packages.php` pattern: run the
 * popcorn {@see AttributedClassScanner} once over the configured
 * discover-paths (plus the explicit `resources.classes` list), resolve the full class-string set,
 * and write it to {@see FrameResourceManifest}. At boot, `discoverResources()` reads this manifest
 * instead of re-walking the filesystem every request. Re-run on deploy (or after adding a resource).
 */
class FrameCacheCommand extends Command
{
    protected $signature = 'splicewire:beam:frame:cache';

    protected $description = 'Cache the discovered #[ParticleResource] class manifest (skips the per-boot filesystem scan).';

    public function handle(ParticleResourceRegistry $registry, FrameResourceManifest $manifest): int
    {
        $explicit = (array) config('beam.core.resources.classes', config('frame.resources', []));
        $paths = (array) config('beam.core.resources.discover_paths', config('frame.discover_paths', []));

        $scanned = $registry->scanPaths($paths);

        $classes = array_values(array_unique([...$explicit, ...$scanned]));

        $manifest->write($classes);

        $this->components->info(sprintf(
            'Cached %d frame resource class%s to %s.',
            count($classes),
            count($classes) === 1 ? '' : 'es',
            $manifest->path(),
        ));

        return self::SUCCESS;
    }
}
