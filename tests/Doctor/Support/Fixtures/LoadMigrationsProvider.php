<?php

namespace Splicewire\Beam\Tests\Doctor\Support\Fixtures;

class LoadMigrationsProvider
{
    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
