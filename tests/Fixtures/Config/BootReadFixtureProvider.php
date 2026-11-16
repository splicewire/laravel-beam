<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Illuminate\Support\ServiceProvider;

/**
 * Fixture (config-sections ticket 06): a provider that reads the live `widgets.enabled` during boot (the defect the
 * boot-read probe and audit exist to catch), a deploy key, and one key it builds at runtime.
 */
class BootReadFixtureProvider extends ServiceProvider
{
    public static ?bool $held = null;

    public function boot(): void
    {
        static::$held = (bool) config('widgets.enabled');
        $driver = config('widgets.driver');
        $key = 'widgets.'.'limits';
        config($key);
        unset($driver);
    }
}
