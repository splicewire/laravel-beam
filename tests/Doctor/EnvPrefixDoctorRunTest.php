<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\EnvPrefixAudit;
use Splicewire\Beam\Doctor\Support\EnvPrefixCensus;
use Splicewire\Beam\Tests\Doctor\Support\EnvPrefixFixtures;
use Splicewire\Beam\Tests\TestCase;

/**
 * env-inventory 02: the env-prefix audit is registered advisory and runs inside `splicewire:beam:doctor`, where it names
 * the population it read.
 */
class EnvPrefixDoctorRunTest extends TestCase
{
    private EnvPrefixFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new EnvPrefixFixtures;
    }

    protected function tearDown(): void
    {
        $this->fixtures->reap();
        parent::tearDown();
    }

    public function test_it_is_registered_advisory_for_beam(): void
    {
        $registrations = [];

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            $registrations[$registration->audit] = $registration;
        }

        $this->assertArrayHasKey(EnvPrefixAudit::class, $registrations);
        $this->assertFalse($registrations[EnvPrefixAudit::class]->gate);
        $this->assertSame('splicewire/laravel-beam', $registrations[EnvPrefixAudit::class]->package);
    }

    public function test_the_doctor_run_reports_it_and_names_the_population(): void
    {
        $packages = $this->fixtures->copy('acme-widgets');
        $this->app->instance(EnvPrefixCensus::class, new EnvPrefixCensus($packages, 'acme/widgets'));
        $this->artisan('splicewire:beam:env-prefix-baseline', ['--write' => true])->assertSuccessful();

        $this->withoutMockingConsoleOutput();
        Artisan::call('splicewire:beam:doctor');
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

        $this->assertStringContainsString(EnvPrefixAudit::CHECK, $output);
        $this->assertStringContainsString('1 family package(s), 2 config file(s), 5 env() read(s)', $output);
    }
}
