<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 22, NOT in the 20/19 land): `/holder/creds` is a declared SECRET object. Below it, `token`
 * carries its OWN `#[ConfigKey]` without `secret: true`, and `bare` carries none.
 */
#[ConfigRoot(root: 'qa.sticky', title: 'Sticky secret')]
class StickySecretSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public StickyHolder $holder,
    ) {}
}
