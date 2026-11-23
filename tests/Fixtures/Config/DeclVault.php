<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 22): `token` declares `secret: false` under a secret parent; `label` declares nothing. */
class DeclVault extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: false)]
        public ?string $token,
        public ?string $label,
    ) {}
}
