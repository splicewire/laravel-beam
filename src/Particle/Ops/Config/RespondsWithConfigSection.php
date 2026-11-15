<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Data\Config\ConfigSectionData;

/**
 * What every `beam-config` write op shares (config-sections ticket 05): the actor's key as the store records it, and
 * the section's row as the one reader reads it after the write, with this process's overlay re-synced first so the
 * row's effective values and provenance reflect a publish at once.
 */
trait RespondsWithConfigSection
{
    protected static function actorKey(mixed $actor): ?string
    {
        return $actor instanceof Authenticatable ? (string) $actor->getAuthIdentifier() : null;
    }

    protected static function row(string $root): ConfigSectionData
    {
        app(ConfigOverlay::class)->sync();

        return app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get($root));
    }
}
