<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 22, F1): an implementation of {@see DeclTransport} with a secret the interface does not declare. */
class DeclSmtpTransport extends Data implements DeclTransport
{
    public function __construct(
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Host, secret: true)]
        public ?string $password,
    ) {}
}
