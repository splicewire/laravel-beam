<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): declared secrets reached through `?self` and `?parent` ({@see TreeBaseNode}). */
#[ConfigRoot(root: 'qa.tree', title: 'Tree')]
class TreeSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public TreeChildNode $node,
    ) {}
}
