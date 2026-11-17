<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * An operator lock on a key (config-sections ticket 07): the tenant tier may not override it on this install, and the
 * tenant's row says why. `pointer` is the locked pointer, which may be an ancestor of the key it is shown on.
 */
#[TypeScript, Title('Config lock')]
class ConfigLockData extends BeamData
{
    public function __construct(
        public string $pointer,
        public string $reason,
        public ?string $lockedAt = null,
    ) {}
}
