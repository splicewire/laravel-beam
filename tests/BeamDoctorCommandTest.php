<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Contracts\Console\Kernel;
use Schemastud\Doctor\DoctorStatus;
use Schemastud\Doctor\Finding;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Doctor\SchemaFormsDoorAudit;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;

class BeamDoctorCommandTest extends TestCase
{
    private string $fixtureBase;

    protected function tearDown(): void
    {
        if (isset($this->fixtureBase) && is_dir($this->fixtureBase)) {
            @unlink($this->fixtureBase.'/composer.json');
            @unlink($this->fixtureBase.'/composer.lock');
            @rmdir($this->fixtureBase);
        }

        parent::tearDown();
    }

    /**
     * Point the app base path at a temp dir holding a fixture composer.json + composer.lock,
     * so the command reads a controlled contract rather than the real project's.
     *
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     */
    private function pointBaseAt(array $composerJson, ?array $composerLock): void
    {
        $this->fixtureBase = sys_get_temp_dir().'/beam-doctor-'.uniqid();
        @mkdir($this->fixtureBase, 0777, true);

        file_put_contents($this->fixtureBase.'/composer.json', json_encode($composerJson));
        if ($composerLock !== null) {
            file_put_contents($this->fixtureBase.'/composer.lock', json_encode($composerLock));
        }

        $this->app->setBasePath($this->fixtureBase);
    }

    // ---- Command integration (render + exit path) ----------------------------------

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('splicewire:beam:doctor', $this->app[Kernel::class]->all());
    }

    public function test_command_succeeds_on_a_clean_git_resolved_contract(): void
    {
        $this->pointBaseAt(
            ['minimum-stability' => 'dev', 'prefer-stable' => true],
            ['packages' => [['name' => 'vendor/pkg', 'dist' => ['type' => 'zip']]], 'packages-dev' => []],
        );

        $this->artisan('splicewire:beam:doctor')->assertExitCode(BeamDoctorCommand::SUCCESS);
    }

    public function test_command_fails_when_lock_has_a_path_package(): void
    {
        $this->pointBaseAt(
            [],
            ['packages' => [['name' => 'vendor/local', 'dist' => ['type' => 'path'], 'source' => ['type' => 'path']]], 'packages-dev' => []],
        );

        $this->artisan('splicewire:beam:doctor')->assertExitCode(BeamDoctorCommand::FAILURE);
    }

    public function test_command_succeeds_when_only_advisories_are_present(): void
    {
        // A dev-main pin with no stability flags trips only the advisory WARN — exit stays SUCCESS.
        $this->pointBaseAt(
            ['require' => ['some/pkg' => 'dev-main']],
            ['packages' => [], 'packages-dev' => []],
        );

        $this->artisan('splicewire:beam:doctor')->assertExitCode(BeamDoctorCommand::SUCCESS);
    }

    // ---- BeamDependencyContractAudit (the hard gate) -------------------------------

    public function test_dependency_audit_fails_on_a_path_package_in_the_lock(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            [],
            ['packages' => [['name' => 'vendor/local', 'source' => ['type' => 'path']]], 'packages-dev' => []],
        );

        $lockFinding = $this->finding($findings, 'lock path-free');
        $this->assertSame(DoctorStatus::Fail, $lockFinding->status);
        $this->assertStringContainsString('vendor/local', $lockFinding->detail);
    }

    public function test_dependency_audit_fails_on_a_committed_path_repository(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            ['repositories' => [['type' => 'path', 'url' => '../../schemastud/laravel-doctor']]],
            ['packages' => [], 'packages-dev' => []],
        );

        $repoFinding = $this->finding($findings, 'repos git-resolved');
        $this->assertSame(DoctorStatus::Fail, $repoFinding->status);
    }

    public function test_dependency_audit_passes_on_a_clean_git_resolved_lock(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            ['repositories' => [['type' => 'git', 'url' => 'https://example.test/pkg.git']]],
            ['packages' => [['name' => 'vendor/pkg', 'dist' => ['type' => 'zip']]], 'packages-dev' => []],
        );

        $this->assertSame(DoctorStatus::Pass, $this->finding($findings, 'lock path-free')->status);
        $this->assertSame(DoctorStatus::Pass, $this->finding($findings, 'repos git-resolved')->status);
    }

    public function test_dependency_audit_warns_when_lock_is_missing(): void
    {
        $findings = (new BeamDependencyContractAudit)->run([], null);

        $this->assertSame(DoctorStatus::Warn, $this->finding($findings, 'lock path-free')->status);
    }

    public function test_dependency_audit_warns_on_dev_main_pin_without_stability_flags(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            ['require' => ['some/pkg' => 'dev-main']],
            ['packages' => [], 'packages-dev' => []],
        );

        $this->assertSame(DoctorStatus::Warn, $this->finding($findings, 'stability configured')->status);
    }

    // ---- Advisory audits: PASS/skip when the optional rung is absent ----------------

    public function test_schema_round_trip_audit_passes_and_skips_when_data_schemas_absent(): void
    {
        // The testbench env does not install data-schemas; expect a PASS skip note (never FAIL).
        $finding = (new SchemaRoundTripAudit)->run();

        $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        // data-schemas may or may not autoload in the co-dev monorepo; either way it is never a FAIL.
        $this->assertContains($finding->status, [DoctorStatus::Pass]);
    }

    public function test_frame_manifest_audit_passes_and_skips_when_frame_absent(): void
    {
        $finding = (new FrameManifestAudit)->run();

        $this->assertNotSame(DoctorStatus::Fail, $finding->status);
    }

    public function test_schema_forms_door_audit_passes_when_schema_forms_absent(): void
    {
        $finding = (new SchemaFormsDoorAudit)->run();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function finding(array $findings, string $check): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check '{$check}'.");
    }
}
