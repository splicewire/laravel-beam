<?php

namespace Splicewire\Beam\Tests\Ia;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Ia\HostIa;
use Splicewire\Beam\Ia\Side;
use Splicewire\Beam\Ia\SideRefused;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough UX-07 (M6, IA-6): a cross-instance surface declares the Side it serves, and a host mounts only the
 * sides it plays (`beam.core.ia.plays`). A refused surface THROWS, because the host wrote the call; it never hides by
 * quietly registering nothing. Undeclared `plays` serves every side, so adopting this leaves every linked host
 * unchanged until it declares.
 */
class SideTest extends TestCase
{
    private function ia(): HostIa
    {
        return $this->app->make(HostIa::class);
    }

    public function test_an_undeclared_host_plays_every_side_so_no_linked_host_changes(): void
    {
        config(['beam.core.ia.plays' => null]);

        $this->assertTrue($this->ia()->plays(Side::Hub));
        $this->assertTrue($this->ia()->plays(Side::Client));
    }

    public function test_a_host_plays_exactly_the_declared_sides(): void
    {
        config(['beam.core.ia.plays' => ['hub']]);
        $this->assertTrue($this->ia()->plays(Side::Hub));
        $this->assertFalse($this->ia()->plays(Side::Client));

        config(['beam.core.ia.plays' => []]);
        $this->assertFalse($this->ia()->plays(Side::Hub));
        $this->assertFalse($this->ia()->plays(Side::Client));
    }

    public function test_a_hub_host_calling_a_client_surface_throws_and_registers_no_route(): void
    {
        config(['beam.core.ia.plays' => ['hub']]);

        try {
            $this->ia()->serve(Side::Client, 'splicewirePlatformConnectionRoutes', function () {
                Route::get('operator/platform-connection', fn () => 'x')->name('operator.platform-connection');
            });
            $this->fail('A refused surface must throw.');
        } catch (SideRefused $refused) {
            $this->assertStringContainsString('splicewirePlatformConnectionRoutes', $refused->getMessage());
        }

        Route::getRoutes()->refreshNameLookups();
        $this->assertFalse(Route::has('operator.platform-connection'));
    }

    public function test_every_route_a_served_surface_registers_carries_its_side(): void
    {
        config(['beam.core.ia.plays' => ['hub']]);

        $this->ia()->serve(Side::Hub, 'device pairing', function () {
            Route::prefix('device')->name('device.')->group(function () {
                Route::get('/', fn () => 'x')->name('show');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame('hub', Route::getRoutes()->getByName('device.show')->getAction('side'));
    }
}
