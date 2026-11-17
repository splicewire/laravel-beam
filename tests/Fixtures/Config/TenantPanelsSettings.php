<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture ({@see TenantPanelsGroup::$settings}, config-sections 07 review-r1, the nearest-declaration boundary): a record
 * whose own ceiling is the OPERATOR's, nested in a tenant-overridable record, holding an explicitly tenant-owned `label`
 * (nearest declaration wins), an operator-only `fixed`, and a tenant-owned map `tags` (its entries resolve to the map's
 * declaration).
 */
class TenantPanelsSettings extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public string $fixed,
        /** @var array<string, string> */
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live), MapValues('string')]
        public array $tags,
    ) {}
}
