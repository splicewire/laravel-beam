<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): a property typed as a union of TWO Data classes that disagree on whether `apiKey` is
 * secret. The declaration fails towards secret: a key is secret if it is secret in ANY member. Read only through
 * `keyDeclaration()`; the classes share this file because only that test reads them.
 */
#[ConfigRoot(root: 'qa.union-either', title: 'Union of two Data classes')]
class UnionEitherSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public UnionPlainInner|UnionSecretInner|Optional $either,
    ) {}
}

/** `apiKey` is NOT secret here; {@see UnionSecretInner} declares it secret. */
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
