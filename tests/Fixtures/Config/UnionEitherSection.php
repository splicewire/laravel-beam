<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): a property typed as a union of TWO Data classes that disagree on whether `apiKey` is
 * secret ({@see UnionPlainInner}, {@see UnionSecretInner}). The declaration fails towards secret: a key is secret if it is
 * secret in ANY member. Read only through `keyDeclaration()`.
 */
#[ConfigRoot(root: 'qa.union-either', title: 'Union of two Data classes')]
class UnionEitherSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public UnionPlainInner|UnionSecretInner|Optional $either,
    ) {}
}
