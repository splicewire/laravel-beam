<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): {@see FoldSection} with the union's members written in the other order. */
#[ConfigRoot(root: 'qa.fold-reversed', title: 'Fold, reversed')]
class FoldReversedSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public FoldHostData|FoldTenantData|Optional $x,
    ) {}
}
