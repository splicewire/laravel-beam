<?php

namespace Schemastud\Beam\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Schemastud\Beam\BeamServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam boots with ONLY its own provider — no frame, no editor rung. If this
     * list ever needs the frame provider to make beam work, the layering has
     * inverted (ADR-0082) and the test should fail loudly.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // beam's own provider. NOT the frame/editor rung — the layering law
            // (ADR-0082) is that beam boots without frame; BeamBootTest asserts it.
            BeamServiceProvider::class,
            // A declared dependency, not a rung above beam: the media traits register
            // spatie/laravel-medialibrary collections/conversions, whose machinery reads
            // `media-library.*` config (file_namer, optimizers, …) at registration time.
            MediaLibraryServiceProvider::class,
        ];
    }
}
