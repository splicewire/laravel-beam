<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** A section's key counts, for an index row: leaf keys, and how many are overridable, deploy-only, secret, env-fed. */
#[TypeScript]
class ConfigSectionCountsData extends BeamData
{
    public function __construct(
        public int $keys,
        public int $overridable,
        public int $deploy,
        public int $secrets,
        public int $envBound,
    ) {}
}
