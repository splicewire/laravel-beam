<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20 r3): union members that disagree only on env, null against '' (in both orders). */
#[ConfigRoot(root: 'qa.env-conflict', title: 'Env conflict')]
class EnvConflictSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public EnvNullData|EnvEmptyData|Optional $nullFirst,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public EnvEmptyData|EnvNullData|Optional $emptyFirst,
    ) {}
}
