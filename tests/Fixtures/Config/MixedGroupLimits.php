<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture ({@see MixedGroupSection}): `hard` is host-only, nested inside an operator-owned record; `soft` is the operator's. */
class MixedGroupLimits extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public int $hard,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public int $soft,
    ) {}
}
