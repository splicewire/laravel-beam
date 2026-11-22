<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;

/** Fixture (config-sections 20 r3): an OBJECT path to a secret, no list: `/holder/primary/password`. */
class RelayHolder extends Data
{
    public function __construct(
        public RelayEntry $primary,
    ) {}
}
