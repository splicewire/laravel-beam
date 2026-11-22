<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 19): a record whose ONLY key is an optional secret, so `{}` is a valid value for it. */
class SecretBox extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string|Optional|null $secret,
    ) {}
}
