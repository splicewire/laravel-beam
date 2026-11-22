<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 19): the optional branch of {@see DeepRelayData}, with its own secret. */
class DeepRelayExtra extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $note,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $extraKey,
    ) {}
}
