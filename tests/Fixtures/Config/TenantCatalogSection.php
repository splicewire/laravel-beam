<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections ticket 09): an EXTEND map a workspace may add to, standing in for the flagship's
 * `app.chat.models` (TenantModelCatalog's floor-wins merge). `catalog` is merged with `extend`: the tenant tier may add
 * entries, never replace the platform's. `routes` is an ordinary (replace) map beside it, so a non-extend key is
 * proven unchanged.
 */
#[ConfigRoot(root: 'tcatalog', title: 'Tenant catalog', description: 'A catalog a workspace may extend.')]
class TenantCatalogSection extends ConfigSection
{
    public function __construct(
        #[Title('Catalog'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live, merge: ConfigMerge::Extend), MapValues('string')]
        public array $catalog,

        #[Title('Routes'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live), MapValues('string')]
        public array $routes,
    ) {}
}
