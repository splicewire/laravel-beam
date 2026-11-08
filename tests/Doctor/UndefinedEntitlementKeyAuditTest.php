<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\UndefinedEntitlementKeyAudit;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 04: an `entitlement:{key}` referenced but known to no source is named, not just denied. Static and advisory:
 * every finding states what it cannot see.
 */
class UndefinedEntitlementKeyAuditTest extends TestCase
{
    private function finding(): object
    {
        $findings = $this->app->make(UndefinedEntitlementKeyAudit::class)->run();
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    private function operation(string $name, string $ability): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'entitlement-probe',
            name: $name,
            kind: OperationKind::Read,
            model: BeamParticle::class,
            handle: fn () => null,
            ability: $ability,
        ));
    }

    public function test_it_passes_when_every_referenced_key_is_known_and_says_what_it_cannot_see(): void
    {
        config(['beam.core.entitlements.keys' => ['calculators.profit']]);
        Route::get('/paid', fn () => 'ok')->middleware('can:entitlement:calculators.profit');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertTrue($finding->conclusive);
        $this->assertStringContainsString('Coverage is incomplete by design', $finding->detail);
    }

    public function test_it_names_each_undefined_key_and_where_it_is_referenced(): void
    {
        config(['beam.core.entitlements.keys' => ['calculators.profit']]);
        Route::get('/paid', fn () => 'ok')->middleware('can:entitlement:calculators.profit');
        Route::get('/ghost', fn () => 'ok')->middleware('can:entitlement:calculators.ghost');
        $this->operation('unlock', 'entitlement:plans.ghost');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Warn, $finding->status, 'advisory: a warning, never a failure');
        $this->assertStringContainsString('2 of 3 referenced entitlement keys', $finding->detail);
        $this->assertMatchesRegularExpression('/calculators\.ghost\s+← route GET\|HEAD ghost/', $finding->detail);
        $this->assertMatchesRegularExpression('/plans\.ghost\s+← operation entitlement-probe\.unlock ability:/', $finding->detail);
        $this->assertStringNotContainsString('calculators.profit ', $finding->detail);
        $this->assertStringContainsString('Gate checks in PHP, Blade `@can`', $finding->detail);
    }

    public function test_an_undeclared_realm_gate_key_is_named_from_either_config_shape(): void
    {
        config(['beam.core.entitlements.keys' => ['os.operate']]);
        config(['beam.core.realms.operator.gate' => ['entitlement' => 'os.operate', 'mode' => 'hard']]);
        config(['beam.core.realms.exec.gate' => ['entitlement' => 'exec.ghost', 'mode' => 'hard']]);
        config(['beam.core.realm_gates' => ['site' => ['entitlement' => 'site.ghost', 'mode' => 'soft']]]);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertMatchesRegularExpression('/exec\.ghost\s+← realm gate exec/', $finding->detail);
        $this->assertMatchesRegularExpression('/site\.ghost\s+← realm gate site/', $finding->detail);
        $this->assertStringNotContainsString('os.operate ', $finding->detail, 'a declared realm key is not named');
    }

    public function test_a_contributed_key_counts_as_known(): void
    {
        $this->app->make(\Splicewire\Beam\Entitlements\EntitlementKeyUniverse::class)->contribute(fn (): array => ['bundle.key']);
        $this->operation('bundled', 'entitlement:bundle.key');

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    public function test_it_is_inconclusive_with_nothing_to_check(): void
    {
        $finding = $this->finding();

        $this->assertFalse($finding->conclusive, 'nothing to check is not a pass');
        $this->assertStringContainsString('No `entitlement:*` reference', $finding->detail);
    }

    public function test_it_is_registered_with_the_beam_doctor(): void
    {
        $audits = array_map(fn ($registration): string => $registration->audit, $this->app->make(BeamDoctorManifest::class)->registrations());

        $this->assertContains(UndefinedEntitlementKeyAudit::class, $audits);
    }
}
