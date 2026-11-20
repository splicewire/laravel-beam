<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use RuntimeException;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections ticket 09): a store whose TENANT tier read fails, as a dropped tenant connection does. */
class UnreadableTenantStore extends ConfigOverrideStore
{
    public function publishedLayers(): array
    {
        if ($this->tier() === ConfigTier::Tenant) {
            throw new RuntimeException('the tenant connection is down');
        }

        return parent::publishedLayers();
    }
}
