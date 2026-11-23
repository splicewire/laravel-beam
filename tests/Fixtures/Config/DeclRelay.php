<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 21): one record in a LIST, with a secret `password`. */
class DeclRelay extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true, closed: [])]
        public ?string $password,
    ) {}
}
