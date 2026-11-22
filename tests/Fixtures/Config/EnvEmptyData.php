<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 20 r3, Codex r2 LOW): `k` names the EMPTY env name '' (a different declaration from null). */
class EnvEmptyData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, env: '')]
        public string $k,
    ) {}
}
