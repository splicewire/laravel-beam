<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): one mailer. `driver` carries the bare `#[ConfigKey]` the generator puts on every entry
 * property (ceiling Host); `password` is a secret; `auth` is a union nested in a map entry, with its own secret below.
 */
class MailerEntry extends Data
{
    public function __construct(
        #[ConfigKey]
        public string $driver,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $password,
        public UnionSecretInner|Optional $auth,
    ) {}
}
