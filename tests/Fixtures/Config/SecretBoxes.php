<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;

/** Fixture (config-sections 19): `/boxes/box/secret`, an object path whose middle object may be empty. */
class SecretBoxes extends Data
{
    public function __construct(
        public SecretBox $box,
    ) {}
}
