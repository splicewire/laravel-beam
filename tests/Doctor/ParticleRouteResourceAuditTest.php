<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ParticleRouteResourceAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The reportable absence that replaced a per-route throw (api-surface-coherence 102).
 *
 * The load-bearing assertion is {@see test_an_unregistered_stamp_warns_and_never_fails}: `Warn`, not
 * `Fail`. `inResource($key, filters: true)` legitimately names a data-filters key with no
 * `#[ParticleResource]` — the flagship's `guest-links` and `releases` both — so failing here would only
 * move the outage from the spec build to the doctor's exit code.
 */
class ParticleRouteResourceAuditTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // The audit distinguishes "data-filters only" from "registered nowhere", so the filter registry
        // has to be bound for the finding text to be decidable at all.
        return [...parent::getPackageProviders($app), DataFiltersServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: BeamParticle::class,
            frame: false,
        ));
    }

    private function audit(): ParticleRouteResourceAudit
    {
        return new ParticleRouteResourceAudit(
            app(Router::class),
            app(ParticleResourceRegistry::class),
            app(ParticleOperationRegistry::class),
        );
    }

    private function stamp(string $uri, string $key): void
    {
        app(Router::class)->get($uri, fn () => null)->defaults(ParticleController::RESOURCE, $key);
    }

    public function test_a_host_with_no_particle_routes_passes_rather_than_reporting_nothing(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ParticleRouteResourceAudit::CHECK, $findings[0]->check);
    }

    public function test_stamps_that_all_resolve_pass(): void
    {
        $this->stamp('widgets', 'widgets');
        $this->stamp('widgets/{id}', 'widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('2 particle-stamped routes', $findings[0]->detail);
    }

    public function test_an_unregistered_stamp_warns_and_never_fails(): void
    {
        $this->stamp('widgets', 'widgets');
        $this->stamp('guest-tokens', 'guest-links');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('[guest-links]', $findings[0]->detail);
        $this->assertStringContainsString('GET guest-tokens', $findings[0]->detail);
        $this->assertStringContainsString('registered in NEITHER registry', $findings[0]->detail);
    }

    public function test_an_unregistered_operation_stamp_is_reported_on_its_own_axis(): void
    {
        app(Router::class)->post('widgets/{id}/op/publish', fn () => null)->defaults(
            ParticleOperationController::RESOURCE, 'widgets',
        )->defaults(ParticleOperationController::NAME, 'publish');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('op:widgets:publish', $findings[0]->detail);
        $this->assertStringContainsString('no such particle operation', $findings[0]->detail);
    }
}
