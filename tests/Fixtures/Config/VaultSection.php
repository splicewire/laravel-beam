<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/** A record-shaped fixture whose secret is NESTED under a nullable map a host may replace whole. */
#[ConfigRoot(root: 'vault', title: 'Vault')]
class VaultSection extends ConfigSection
{
    public function __construct(
        public ?VaultConnection $connection,
        public string $driver,
    ) {}
}
