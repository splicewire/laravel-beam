<?php

namespace Schemastud\Beam\Tests;

use Schemastud\Beam\BeamServiceProvider;

class BeamBootTest extends TestCase
{
    public function test_beam_boots_headless_without_the_frame_editor_rung(): void
    {
        // The application came up with only BeamServiceProvider registered
        // (see TestCase::getPackageProviders). No frame/editor package is present.
        $this->assertTrue($this->app->isBooted());

        $this->assertArrayHasKey(BeamServiceProvider::class, $this->app->getLoadedProviders());

        // The frame editor rung must NOT be loaded for beam to run.
        $this->assertArrayNotHasKey('Schemastud\\Frame\\FrameServiceProvider', $this->app->getLoadedProviders());
    }

    public function test_beam_config_is_published_and_readable(): void
    {
        $this->assertIsArray(config('beam'));
    }
}
