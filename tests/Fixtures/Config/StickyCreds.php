<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 22): the children of a SECRET object. `token` has its own declaration WITHOUT `secret: true`
 * (the shape under test); `bare` has none, so it inherits the object's secret (the control).
 */
class StickyCreds extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $token,
        public string $bare,
    ) {}
}
