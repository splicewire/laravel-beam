<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 05 second fix, the boundary the other way): an operator-owned object holding an
 * operator-owned map (`routes`, replaced whole) and an operator-owned record (`limits`) with ONE host-only key nested
 * inside it (`limits.hard`). A parent write must inherit `/limits/hard` alone, never `/limits` or `/routes`.
 */
#[ConfigRoot(root: 'qa.mixed', title: 'Mixed ownership')]
class MixedGroupSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public MixedGroupData $group,
    ) {}
}
