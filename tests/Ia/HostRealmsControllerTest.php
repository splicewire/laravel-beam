<?php

namespace Splicewire\Beam\Tests\Ia;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Ia\HostRealmsData;
use Splicewire\Beam\Ia\Http\HostRealmsController;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough UX-05 (M2, OQ-5's default emission site): the SPA reads HostRealmsData per request from a packaged
 * endpoint that declares its response with `#[ResponseFromData]`. A host mounts it; it authors no realm payload.
 */
class HostRealmsControllerTest extends TestCase
{
    public function test_it_answers_the_host_realms_for_the_path_the_spa_is_on(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver([]));
        Route::get('dashboard', fn () => 'app')->name('dashboard');
        Route::get('operator', fn () => 'operator')->name('operator.home');
        Route::get('api/realms', HostRealmsController::class);
        app('router')->getRoutes()->refreshNameLookups();

        $this->getJson('api/realms?path=/operator/tenants')
            ->assertOk()
            ->assertJsonPath('current', 'operator')
            ->assertJsonPath('back', ['label' => 'App', 'href' => '/dashboard'])
            ->assertJsonPath('realms.0.key', 'operator');
    }

    public function test_it_declares_its_response_as_host_realms_data(): void
    {
        $declared = array_map(
            fn ($a) => $a->newInstance()->dataClass,
            (new ReflectionClass(HostRealmsController::class))->getMethod('__invoke')->getAttributes(ResponseFromData::class),
        );

        $this->assertSame([HostRealmsData::class], $declared);
    }
}
