<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/** Fixture (config-sections 20): the strict member of {@see FoldSection}'s union: the bare default (host, replace, deploy). */
class FoldHostData extends Data
{
    public function __construct(
        #[ConfigKey]
        public string $k,
    ) {}
}
