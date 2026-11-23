<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 17): `account` is closed to the operator; `label` declares only its ceiling. */
class ClosedCreds extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant, closed: [ConfigTier::Operator])]
        public string $account,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $label,
    ) {}
}
