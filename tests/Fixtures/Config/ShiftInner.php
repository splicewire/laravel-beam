<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20 r3, review r2 MEDIUM 2): an inner class whose keys declare their OWN ceilings: `host` the
 * bare default (host), `open` the tenant tier, above the operator ceiling the outer property declares.
 */
class ShiftInner extends Data implements HoldsCredentials
{
    public function __construct(
        #[ConfigKey]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $open,
    ) {}
}
