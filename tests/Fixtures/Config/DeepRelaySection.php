<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 19): one operator-overridable object, {@see DeepRelayData}, with declared secrets at one, two
 * and three levels below it, two secrets under one parent, a host-only key, and an optional branch that holds a secret.
 */
#[ConfigRoot(root: 'qa.deep', title: 'Deep relay')]
class DeepRelaySection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public DeepRelayData $relay,
    ) {}
}
