<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (build-qa's ProtectedMapInheritanceQaTest, 2026-11-16; credited): an operator-overridable object holding a
 * HOST-ONLY map, so a parent write that resends the map unchanged must still let a new host entry through.
 */
#[ConfigRoot(root: 'qa.protected-map', title: 'Protected deployment map')]
class ProtectedMapSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ProtectedMapFields $group,
    ) {}
}
