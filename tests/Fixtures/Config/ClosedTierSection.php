<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 17): keys a tenant may set while the OPERATOR tier is kept out.
 *
 * - `api_key`: a secret with ceiling `Tenant`, closed to the operator (a tenant's own credential);
 * - `creds`: an object open to both tiers whose child `account` is closed to the operator (cut out of an object entry);
 * - `sealed`: an object closed to the operator whose child `label` declares nothing more (closure is sticky);
 * - `region`: open to both tiers (the control);
 * - `audit_only`: closed to the TENANT tier, open to the operator;
 * - `accounts`: a LIST of {@see ClosedCreds}. The list key declares nothing closed; its items `account` closes it to the
 *   operator, since a list is one value (Codex review, lane A).
 */
#[ConfigRoot(root: 'qa.closed', title: 'Closed tiers')]
class ClosedTierSection extends ConfigSection
{
    /** @param  array<int, ClosedCreds>  $accounts */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, secret: true, closed: [ConfigTier::Operator])]
        public ?string $api_key,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public ClosedCreds $creds,
        #[ConfigKey(ceiling: ConfigTier::Tenant, closed: [ConfigTier::Operator])]
        public ClosedCreds $sealed,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $region,
        #[ConfigKey(ceiling: ConfigTier::Tenant, closed: [ConfigTier::Tenant])]
        public string $audit_only,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        #[DataCollectionOf(ClosedCreds::class)]
        public array $accounts,
    ) {}
}
