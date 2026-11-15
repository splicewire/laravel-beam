<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** A section's operator-tier version history, newest first: the `beam-config` `versions` op's output (ticket 05). */
#[TypeScript]
class ConfigVersionsData extends BeamData
{
    /** @param  list<ConfigVersionData>  $versions */
    public function __construct(
        public string $root,
        #[DataCollectionOf(ConfigVersionData::class)]
        public array $versions,
    ) {}
}
