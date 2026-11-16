<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 05, the inherit-list fix): an operator-overridable map with REPLACE semantics, so a `set` of
 * the whole map must still drop an entry it leaves out.
 */
#[ConfigRoot(root: 'qa.maps', title: 'Mapped settings')]
class MapGroupSection extends ConfigSection
{
    /** @param  array<string, string>  $routes */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live), MapValues('string')]
        public array $routes,
    ) {}
}
