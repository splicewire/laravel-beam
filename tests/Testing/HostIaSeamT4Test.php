<?php

namespace Splicewire\Beam\Tests\Testing;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Ia\Side;
use Splicewire\Beam\Ia\Sides;
use Splicewire\Beam\Testing\AssertsHostIaSeam;
use Splicewire\Beam\Tests\TestCase;

/**
 * T4 is structural (ux-walkthrough UX-07): it reads the side a route was SERVED for (Sides::serve tags the route) and
 * the sides the host declares in `beam.core.ia.plays`, rather than guessing a side from a route's name or path. A
 * cross-instance route that declares no side is still found, as `undeclared-side`.
 */
class HostIaSeamT4Test extends TestCase
{
    use AssertsHostIaSeam;

    protected function hostIaRatchet(): array
    {
        return [];
    }

    protected function hostIaSweepRoots(): array
    {
        return [];
    }

    public function test_the_declared_sides_are_what_t4_judges(): void
    {
        config(['beam.core.ia.plays' => ['client']]);

        $this->assertSame(['client'], $this->hostIaPlays());
    }

    public function test_a_route_served_for_a_side_the_host_no_longer_plays_is_a_violation(): void
    {
        // Served while the host played both, then the host declared [client]: the hub route is left behind.
        config(['beam.core.ia.plays' => null]);
        Sides::serve(Side::Hub, 'device pairing', function () {
            Route::get('device', fn () => 'x')->name('device.show');
        });
        Route::getRoutes()->refreshNameLookups();
        config(['beam.core.ia.plays' => ['client']]);

        $this->assertArrayHasKey('T4 hub-route device.show', $this->hostIaT4());
    }

    public function test_a_cross_instance_route_with_no_declared_side_is_named(): void
    {
        config(['beam.core.ia.plays' => ['hub']]);
        Route::get('device', fn () => 'x')->name('device.show');
        Route::getRoutes()->refreshNameLookups();

        $this->assertArrayHasKey('T4 undeclared-side device.show', $this->hostIaT4());
    }

    public function test_a_served_route_for_a_played_side_is_clean(): void
    {
        config(['beam.core.ia.plays' => ['hub']]);
        Sides::serve(Side::Hub, 'device pairing', function () {
            Route::get('device', fn () => 'x')->name('device.show');
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame([], $this->hostIaT4());
    }

    public function test_a_host_that_runs_the_seam_without_declaring_its_sides_is_named(): void
    {
        config(['beam.core.ia.plays' => null]);

        $this->assertArrayHasKey('T4 plays-undeclared', $this->hostIaT4());
    }
}
