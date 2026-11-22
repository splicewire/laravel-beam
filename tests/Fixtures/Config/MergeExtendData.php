<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): {@see MergeConflictSection}'s extend member; `env` names one variable. */
class MergeExtendData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, merge: ConfigMerge::Extend, env: 'QA_MERGE_EXTEND')]
        public string $k,
    ) {}
}
