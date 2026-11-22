<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): a declared secret below an INTERSECTION-typed property (`Data&Interface`). */
#[ConfigRoot(root: 'qa.intersect', title: 'Intersection')]
class IntersectSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public IntersectInner&HoldsCredentials $creds,
    ) {}
}
