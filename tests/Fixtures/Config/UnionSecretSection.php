<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): a declared secret below a UNION-typed Data property, in the two optional forms the
 * generator and authors write: `Nested|Optional` and `Nested|Optional|null`.
 */
#[ConfigRoot(root: 'qa.union', title: 'Union secrets')]
class UnionSecretSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public UnionSecretInner|Optional $opt,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public UnionSecretInner|Optional|null $optNull,
    ) {}
}
