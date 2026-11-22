<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): {@see MergeConflictSection} with the union's members written in the other order. */
#[ConfigRoot(root: 'qa.merge-reversed', title: 'Merge conflict, reversed')]
class MergeConflictReversedSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public MergeReplaceData|MergeExtendData|Optional $y,
    ) {}
}
