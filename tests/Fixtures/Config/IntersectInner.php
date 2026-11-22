<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): the Data member of an intersection type; `token` is a secret. */
class IntersectInner extends Data implements HoldsCredentials
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $token,
    ) {}
}
