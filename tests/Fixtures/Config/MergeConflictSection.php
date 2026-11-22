<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20, Codex HIGH): a union of two Data classes that AGREE on the ceiling (operator) and DISAGREE
 * on merge: {@see MergeExtendData} says extend, {@see MergeReplaceData} says replace. The fold keeps extend (an override may
 * add, never replace), in either member order ({@see MergeConflictReversedSection}).
 */
#[ConfigRoot(root: 'qa.merge', title: 'Merge conflict')]
class MergeConflictSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public MergeExtendData|MergeReplaceData|Optional $y,
    ) {}
}
