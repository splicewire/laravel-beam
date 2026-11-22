<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 19): the second level of {@see DeepRelayData}; `apiKey` is a secret two levels down. */
class DeepRelayInner extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $region,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $apiKey,
        public DeepRelayCore $core,
    ) {}
}
