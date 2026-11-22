<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 19 r4): a record whose keys the TENANT tier may write; `password` is a secret. */
class TenantPrimary extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Tenant, secret: true)]
        public string|Optional|null $password,
    ) {}
}
