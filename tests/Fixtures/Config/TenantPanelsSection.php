<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections ticket 07): a section for the tenant tier. `review` and `theme` are overridable up to the
 * tenant tier (`review` stands in for beam-ux's `releases.review_required`); `limit` stops at the operator tier, so it is
 * never offered to a tenant; `driver` keeps the default `host` ceiling.
 */
#[ConfigRoot(root: 'tpanels', title: 'Tenant panels', description: 'Panels a workspace may tune.')]
class TenantPanelsSection extends ConfigSection
{
    public function __construct(
        #[Title('Review required'), Description('Publishes go to review first.')]
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public bool $review,
        #[Title('Theme')]
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string $theme,
        #[Title('Limit')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public int $limit,
        #[Title('Driver')]
        public string $driver,
        // review-r1 (07): a tenant-overridable record (children keep their own ceilings) and a tenant map (REPLACE).
        #[Title('Group'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public TenantPanelsGroup $group,
        /** @var array<string, string> */
        #[Title('Routes'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live), MapValues('string')]
        public array $routes,
        // review-r1 01:26Z (07): a NULLABLE tenant map, so a tenant can publish an explicit null or an empty map parent.
        /** @var array<string, string>|null */
        #[Title('Extras'), ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live), MapValues('string')]
        public ?array $extras,
    ) {}
}
