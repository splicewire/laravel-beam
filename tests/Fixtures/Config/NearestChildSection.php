<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (build-qa's NearestChildOwnershipQaTest, 2026-11-16; credited): the inverse boundary. A HOST-ONLY record
 * (`group.limits`, {@see NearestChildGroup}) holding an explicitly operator-owned child (`limits.soft`); the nearest
 * declaration wins, so the child stays the operator's inside a parent write.
 */
#[ConfigRoot(root: 'qa.nearest-child', title: 'Nearest child ownership')]
class NearestChildSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public NearestChildGroup $group,
    ) {}
}
