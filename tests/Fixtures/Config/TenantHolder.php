<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;

/** Fixture (config-sections 19 r4): the tenant-writable object path `/holder/primary/password`. */
class TenantHolder extends Data
{
    public function __construct(
        public TenantPrimary $primary,
    ) {}
}
