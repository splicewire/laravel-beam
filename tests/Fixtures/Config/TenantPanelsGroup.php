<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture ({@see TenantPanelsSection}, config-sections 07 review-r1): a tenant-overridable record whose children keep their
 * own ceilings. `review` and `label` admit the tenant tier; `deploy` is host-only.
 */
class TenantPanelsGroup extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public bool $review,
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        // review-r1 (07, nearest declaration): an OPERATOR-owned, nullable record with a tenant-owned child inside.
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public ?TenantPanelsSettings $settings,
    ) {}
}
