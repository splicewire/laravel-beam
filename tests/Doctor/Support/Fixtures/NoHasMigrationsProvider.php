<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures;

class NoHasMigrationsProvider
{
    public function configurePackage(): void
    {
        // Ships a config file only — no migrations wired at all.
    }
}
