<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20 r4): a list of records with a secret that the TENANT tier may override. */
#[ConfigRoot(root: 'qa.trelay', title: 'Tenant relays')]
class TenantRelaySection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public RelayGroup $group,
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public TenantHolder $holder,
    ) {}
}
