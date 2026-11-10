<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

class VaultConnection extends Data
{
    public function __construct(
        public string $host,
        #[ConfigKey(secret: true)]
        public string $password,
    ) {}
}
