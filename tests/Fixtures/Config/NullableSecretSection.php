<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20, root's regression): the NULLABLE form `?Nested` is a single named type, which the
 * declaration walk always followed. Kept so a change to the union walk never breaks it.
 */
#[ConfigRoot(root: 'qa.nullable', title: 'Nullable nested')]
class NullableSecretSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?UnionSecretInner $nullable,
    ) {}
}
