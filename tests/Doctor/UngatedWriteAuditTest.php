<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Splicewire\Beam\Doctor\UngatedWriteAudit;
use Splicewire\Beam\Http\UngatedWrites;
use Splicewire\Beam\Tests\TestCase;

/**
 * app-walkthrough APP-08 (APP-11): a hand-written write route described by the trio (`#[RequestFromData]`) has no
 * `ability:` slot, so nothing declares who may call it unless the route or the handler does. `http.ungated-write`
 * names every such POST/PUT/PATCH/DELETE route with no gate: no `can:`/`require.*` middleware, no authorization call
 * in its handler, and no `authorize()` on its request Data. A route with no authentication at all is a PUBLIC door.
 */
class UngatedWriteAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('fixture/open', [UngatedWriteFixtureController::class, 'open'])->middleware('auth');
        Route::post('fixture/can', [UngatedWriteFixtureController::class, 'open'])->middleware(['auth', 'can:billing.manage']);
        Route::post('fixture/in-handler', [UngatedWriteFixtureController::class, 'checksInHandler'])->middleware('auth');
        Route::post('fixture/data-authorize', [UngatedWriteFixtureController::class, 'dataAuthorizes'])->middleware('auth');
        Route::post('fixture/public', [UngatedWriteFixtureController::class, 'open']);
        Route::post('fixture/untyped', [UngatedWriteFixtureController::class, 'untyped'])->middleware('auth');
        Route::get('fixture/read', [UngatedWriteFixtureController::class, 'open'])->middleware('auth');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_it_names_each_trio_write_with_no_gate_and_says_which_are_public(): void
    {
        $found = app(UngatedWrites::class)->find();

        $this->assertSame(['POST fixture/open', 'POST fixture/public'], array_keys($found));
        $this->assertFalse($found['POST fixture/open']['public']);
        $this->assertTrue($found['POST fixture/public']['public']);
        $this->assertStringContainsString('UngatedWriteFixtureController@open', $found['POST fixture/open']['handler']);
    }

    public function test_the_doctor_warns_with_the_routes_and_passes_at_zero(): void
    {
        $findings = app(UngatedWriteAudit::class)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('POST fixture/open', $findings[0]->detail);
        $this->assertSame(UngatedWriteAudit::CHECK, $findings[0]->check);
    }
}

class UngatedWriteFixtureData {}

class UngatedWriteAuthorizingData
{
    public static function authorize(): bool
    {
        return true;
    }
}

class UngatedWriteFixtureController
{
    #[RequestFromData(UngatedWriteFixtureData::class)]
    public function open(): string
    {
        return 'ok';
    }

    #[RequestFromData(UngatedWriteFixtureData::class)]
    public function checksInHandler(): string
    {
        Gate::authorize('billing.manage');

        return 'ok';
    }

    #[RequestFromData(UngatedWriteAuthorizingData::class)]
    public function dataAuthorizes(): string
    {
        return 'ok';
    }

    public function untyped(): string
    {
        return 'ok';
    }
}
