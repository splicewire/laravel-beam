<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture ({@see MixedGroupSection}): an operator-owned map beside an operator-owned record. */
class MixedGroupData extends Data
{
    /** @param  array<string, string>  $routes */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator), MapValues('string')]
        public array $routes,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public MixedGroupLimits $limits,
    ) {}
}
