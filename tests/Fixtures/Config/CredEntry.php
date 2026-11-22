<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20 r5): an entry of a MAP nested inside a list item (`/relays/<i>/creds/<name>/token`). */
class CredEntry extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string|Optional|null $token,
    ) {}
}
