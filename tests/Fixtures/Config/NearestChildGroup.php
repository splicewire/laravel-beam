<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture ({@see NearestChildSection}): `limits` is host-only; inside it `hard` is host-only and `soft` is the operator's. */
class NearestChildGroup extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public MixedGroupLimits $limits,
    ) {}
}
