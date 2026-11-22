<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): the permissive member of {@see FoldSection}'s union. */
class FoldTenantData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live, merge: ConfigMerge::Extend)]
        public string $k,
    ) {}
}
