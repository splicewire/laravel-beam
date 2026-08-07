<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\Artisan;
use Rushing\Surgeon\Operation\ConformanceManifest;
use Rushing\Surgeon\SurgeonServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Tests\TestCase;

class ConformanceManifestBindingTest extends TestCase
{
    /**
     * The rest of the suite only needs surgeon's INTERFACES autoloadable (composer already gives it
     * that, as a dev dependency) — this is the one test that needs the real `surgeon:audit` command
     * registered, which requires surgeon's own service provider on top of the base TestCase list.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SurgeonServiceProvider::class];
    }

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

    /**
     * The literal acceptance criterion (beam-surgeon-rollout #01): exercises the real `surgeon:audit`
     * command end-to-end, not just the container binding — proving discovery actually happens through
     * the command surface a host would run, with zero host-side wiring beyond requiring both packages.
     */
    public function test_surgeon_audit_command_discovers_beams_registered_audits_with_zero_host_code(): void
    {
        Artisan::call('surgeon:audit', ['--json' => true]);

        $report = json_decode(Artisan::output(), true);

        $this->assertGreaterThan(0, $report['summary']['audits']);
        $this->assertContains(
            HouseStyleAudit::class,
            array_column($report['audits'], 'audit'),
        );
    }
}
