<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 17): {@see ClosedTierSection}'s root and shape BEFORE its package closed any tier, so a test
 * can store operator rows a later declaration closes (the apply-side filter). `creds` was nullable then, and its
 * `account` optional, so a stored parent row could null or omit what the package later closed.
 */
#[ConfigRoot(root: 'qa.closed', title: 'Closed tiers (before)')]
class OpenTierSection extends ConfigSection
{
    /** @param  array<int, OpenCreds>  $accounts */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, secret: true, closed: [])]
        public ?string $api_key,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public ?OpenCreds $creds,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public OpenCreds $sealed,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $region,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $audit_only,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        #[DataCollectionOf(OpenCreds::class)]
        public array $accounts,
    ) {}
}
