<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 19): the third level of {@see DeepRelayData}; `pin` is a secret three levels down. */
class DeepRelayCore extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $zone,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $pin,
    ) {}
}
