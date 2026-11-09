<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The files a section's package and host tiers come from, relative to the install where they sit inside it. */
#[TypeScript]
class ConfigSectionFilesData extends BeamData
{
    public function __construct(
        public ?string $package,
        public ?string $host,
    ) {}
}
