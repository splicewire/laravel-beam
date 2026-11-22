<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20 r3, review r2 MEDIUM 2): the AUTHORIZATION change of the full descent. Below a union and an
 * intersection, a key now takes the inner class's own declaration, not the outer property's (as a plain `Nested` always
 * did): an operator-raised outer over an inner bare #[ConfigKey] is refused, an inner ceiling above the outer is admitted.
 */
#[ConfigRoot(root: 'qa.shift', title: 'Ceiling shift')]
class CeilingShiftSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public ShiftInner|Optional $viaUnion,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public ShiftInner&HoldsCredentials $viaIntersection,
    ) {}
}
