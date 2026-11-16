<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Illuminate\Support\ServiceProvider;

/**
 * Fixture (build-qa's ConfigSections06BoundaryQaTest, 2026-11-16; credited): a provider that takes the live
 * `widgets.enabled` out of the WHOLE configuration during boot, the read shape that evaded the probe at e01b7c0. It reads
 * a deploy key first, as the positive control.
 */
class WholeConfigReadFixtureProvider extends ServiceProvider
{
    public static ?bool $held = null;

    public function boot(): void
    {
        config('widgets.driver');
        $everything = config()->all();
        static::$held = (bool) $everything['widgets']['enabled'];
    }
}
