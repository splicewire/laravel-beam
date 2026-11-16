<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (build-qa probe 3): `deployment` is a host-only map; `label` is the operator's. */
class ProtectedMapFields extends Data
{
    /** @param  array<string, string>  $deployment */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host), MapValues('string')]
        public array $deployment,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
    ) {}
}
