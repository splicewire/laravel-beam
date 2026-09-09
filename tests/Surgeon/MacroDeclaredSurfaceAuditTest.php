<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\MacroDeclaredSurfaceAudit;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The attrition counter for the `->beam()->returns()` macro, which the particle doctrine deprecates
 * "by attrition, not by a rename".
 *
 * ⚠️ These tests pin the DIFFERENCE from {@see \Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit}, not a
 * count. Until 2026-09-09 the two questions were conflated in code — that audit's `isDeclared()` read a
 * bare `'returns'` key nothing writes, while `BeamRouteProxy` writes `action['beam']['returns']` — so
 * every macro-declared route in the estate was reported as declaring no shape at all, overstating the
 * flagship's ratchet by 72 rows. A macro declaration is CORRECT; it is merely on the older site.
 */
class MacroDeclaredSurfaceAuditTest extends TestCase
{
    private function audit(): MacroDeclaredSurfaceAudit
    {
        return $this->app->make(MacroDeclaredSurfaceAudit::class);
    }

    /** @return list<string> */
    private function warnings(): array
    {
        return array_map(
            fn ($f) => $f->detail,
            array_values(array_filter($this->audit()->run(), fn ($f) => $f->status === DoctorStatus::Warn)),
        );
    }

    public function test_a_macro_declared_surface_is_counted_as_migration_debt(): void
    {
        Route::get('api/v1/macro-declared', [MacroFixtureController::class, 'plain'])
            ->name('macro.declared')
            ->beam()->returns(WidgetGateData::class);

        $this->assertStringContainsString('macro.declared', implode("\n", $this->warnings()));
    }

    public function test_it_warns_and_never_fails_because_a_macro_declaration_is_correct(): void
    {
        Route::get('api/v1/macro-warn', [MacroFixtureController::class, 'plain'])
            ->name('macro.warn')
            ->beam()->returns(WidgetGateData::class);

        $statuses = array_map(fn ($f) => $f->status, $this->audit()->run());

        $this->assertNotContains(DoctorStatus::Fail, $statuses, 'migration debt may never join an exit code');
        $this->assertContains(DoctorStatus::Warn, $statuses);
    }

    /**
     * The bug this audit lives beside: a bare flat `'returns'` action key is NOT what the macro writes,
     * and counting it would repeat the mismatch that made the sibling audit wrong in the other direction.
     */
    public function test_a_bare_flat_action_key_is_not_the_macro_and_is_not_counted(): void
    {
        $route = Route::get('api/v1/flat-key', [MacroFixtureController::class, 'plain'])->name('flat.key');
        $route->setAction(array_merge($route->getAction(), ['returns' => WidgetGateData::class]));

        $this->assertStringNotContainsString('flat.key', implode("\n", $this->warnings()));
    }

    public function test_it_passes_when_no_surface_uses_the_macro(): void
    {
        Route::get('api/v1/no-macro', [MacroFixtureController::class, 'plain'])->name('no.macro');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('attrition', $findings[0]->detail);
    }

    public function test_it_is_on_the_doctor_manifest_so_it_actually_runs(): void
    {
        $found = false;

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            if (in_array(MacroDeclaredSurfaceAudit::class, (array) $registration, true)
                || (is_object($registration) && ($registration->audit ?? null) === MacroDeclaredSurfaceAudit::class)) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'an audit off the manifest never runs, which is the failure this asserts against');
    }
}

class MacroFixtureController
{
    public function plain() {}
}
