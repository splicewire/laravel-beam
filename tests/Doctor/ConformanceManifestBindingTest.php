<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Surgeon\Operation\ConformanceManifest;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Tests\TestCase;

class ConformanceManifestBindingTest extends TestCase
{
    /**
     * Generalizes what splicewire-app used to hand-write in its own AppServiceProvider
     * (beam-surgeon-rollout #01): any host with rushing/laravel-surgeon installed gets
     * surgeon:audit discovery of beam's registered audits for free, with zero host-side code.
     * The testbench app has surgeon as a dev dependency, so registerSurgeonAudits() has already
     * pushed beam's audits into BeamDoctorManifest by the time this resolves.
     */
    public function test_conformance_manifest_resolves_and_mirrors_beams_doctor_manifest(): void
    {
        $this->assertTrue($this->app->bound(ConformanceManifest::class));

        $manifest = $this->app->make(ConformanceManifest::class);
        $registered = array_map(fn ($r) => $r->audit, $manifest->registrations());

        $this->assertContains(HouseStyleAudit::class, $registered);
        $this->assertEquals(
            $this->app->make(BeamDoctorManifest::class)->registrations(),
            $manifest->registrations(),
        );
    }
}
