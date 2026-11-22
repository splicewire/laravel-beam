<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): `apiKey` is a declared secret, `label` an ordinary operator key. */
class UnionSecretInner extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $apiKey,
    ) {}
}
