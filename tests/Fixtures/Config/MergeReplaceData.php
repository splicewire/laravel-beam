<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): {@see MergeConflictSection}'s replace member; `env` names a different variable. */
class MergeReplaceData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Reload, env: 'QA_MERGE_REPLACE')]
        public string $k,
    ) {}
}
