<?php

namespace Schemastud\Beam\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Schemastud\Beam\BeamServiceProvider;

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
            BeamServiceProvider::class,
        ];
    }
}
