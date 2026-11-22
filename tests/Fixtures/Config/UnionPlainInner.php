<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20): `apiKey` is NOT secret here; {@see UnionSecretInner} declares it secret. */
class UnionPlainInner extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $apiKey,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $plainOnly,
    ) {}
}
