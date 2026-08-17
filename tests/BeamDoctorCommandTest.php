<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Contracts\Console\Kernel;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\BeamManifestAudit;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Doctor\MarqueeGateAudit;
use Splicewire\Beam\Doctor\McpIsolationAudit;
use Splicewire\Beam\Doctor\SchemaFormsDoorAudit;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;
use Splicewire\Beam\Doctor\SitemapReadinessAudit;
use Splicewire\Beam\Doctor\SurgeonWiringAudit;
use Splicewire\Beam\Surgeon\ComposedTableConfigAudit;
use Splicewire\Beam\Surgeon\ParticleWriteBypassAudit;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;

class BeamDoctorCommandTest extends TestCase
{
    private string $fixtureBase;

    protected function tearDown(): void
    {
        if (isset($this->fixtureBase) && is_dir($this->fixtureBase)) {
            @unlink($this->fixtureBase.'/composer.json');
            @unlink($this->fixtureBase.'/composer.lock');
            @unlink($this->fixtureBase.'/BEAM.md');
            @rmdir($this->fixtureBase);
        }

        parent::tearDown();
    }

    /**
     * Point the app base path at a temp dir holding a fixture composer.json + composer.lock,
     * so the command reads a controlled contract rather than the real project's. Writes a valid
     * BEAM.md by default (the manifest is a gate) unless $withManifest is false.
     *
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     */
    private function pointBaseAt(array $composerJson, ?array $composerLock, bool $withManifest = true): void
    {
        $this->fixtureBase = sys_get_temp_dir().'/beam-doctor-'.uniqid();
        @mkdir($this->fixtureBase, 0777, true);

        file_put_contents($this->fixtureBase.'/composer.json', json_encode($composerJson));
        if ($composerLock !== null) {
            file_put_contents($this->fixtureBase.'/composer.lock', json_encode($composerLock));
        }
        if ($withManifest) {
            file_put_contents(
                $this->fixtureBase.'/BEAM.md',
                "---\nsatellite: fixture\nvariant: inertia-react\n---\n# Fixture\n",
            );
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

    public function test_command_fails_when_the_beam_manifest_is_absent(): void
    {
        // The BEAM.md self-description is a gate (relocated from the satellite doctor): a beam site
        // with no manifest (and no legacy SATELLITE.md fallback) is not conformant.
        $this->pointBaseAt(
            ['minimum-stability' => 'dev', 'prefer-stable' => true],
            ['packages' => [], 'packages-dev' => []],
            withManifest: false,
        );

        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('BEAM.md is missing')
            ->assertExitCode(BeamDoctorCommand::FAILURE);
    }

    // ---- The facade-conformance regime (beam-facade tickets 10 + 19) ---------------

    /**
     * All five registered, all advisory, and — because ticket 18 deleted `StaticBridgeAudit` along with
     * the bridge itself — this registration is what keeps the estate from ever being without a facade
     * signal. That sequencing is the whole reason ticket 10 §6 placed 19 immediately after the cutover.
     *
     * The surgeon-side three ride the same `interface_exists(SuggestsOperations::class)` guard every
     * other surgeon audit here uses; this suite has surgeon installed, so all five are present.
     */
    public function test_the_five_facade_conformance_audits_are_registered_advisory(): void
    {
        $registrations = [];

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            $registrations[$registration->audit] = $registration;
        }

        foreach ([
            StubStaticReferenceAudit::class,
            ConfigFacadeReferenceAudit::class,
            ParticleWriteBypassAudit::class,
            ComposedTableConfigAudit::class,
            TablePrefixBypassAudit::class,
        ] as $audit) {
            $this->assertArrayHasKey($audit, $registrations, "{$audit} is not registered in the doctor manifest.");
            // Advisory, all five. Exactly one audit in the estate gates (UndescribedRegistryAudit), and
            // ticket 10 §5 measured 238 naive flags across 16 repos on day one — which is how a gating
            // check gets its floor bumped and then deleted.
            $this->assertFalse($registrations[$audit]->gate, "{$audit} must be advisory, not a gate.");
            $this->assertSame('splicewire/laravel-beam', $registrations[$audit]->package);
        }
    }

    /** The bridge is gone, so nothing may still be registering a check that only knew how to look for it. */
    public function test_the_deleted_static_bridge_audit_is_not_registered(): void
    {
        $audits = array_map(
            fn ($registration): string => $registration->audit,
            $this->app->make(BeamDoctorManifest::class)->registrations(),
        );

        $this->assertNotContains('Splicewire\Beam\Doctor\StaticBridgeAudit', $audits);
    }

    /**
     * A finding from any of the five must never fail the exit code. The command already has this
     * property for advisories in general; this pins it for the regime that arrived with a measured
     * day-one backlog of its own.
     */
    public function test_a_facade_conformance_finding_does_not_fail_the_exit_code(): void
    {
        $this->pointBaseAt(
            ['minimum-stability' => 'dev', 'prefer-stable' => true],
            ['packages' => [], 'packages-dev' => []],
        );

        $this->app->bind(TablePrefixBypassAudit::class, fn () => new class(new FacadeConformanceScope([])) extends TablePrefixBypassAudit
        {
            public function bypasses(): array
            {
                return [[
                    'file' => 'src/Models/BeamUxEntry.php',
                    'line' => 119,
                    'form' => 'protected $table',
                    'table' => 'beam_ux_entries',
                    'stem' => 'ux_entries',
                ]];
            }
        });

        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('beam_ux_entries')
            ->assertExitCode(BeamDoctorCommand::SUCCESS);
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
            ['repositories' => [['type' => 'path', 'url' => '../../rushing/laravel-doctor']]],
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

    // ---- Relocated base/shell audits (moved from the satellite doctor) --------------

    public function test_beam_manifest_audit_passes_with_identity_keys(): void
    {
        $this->assertSame(DoctorStatus::Pass, (new BeamManifestAudit)->run(true, 'numero', 'inertia-react')->status);
    }

    public function test_beam_manifest_audit_fails_when_missing(): void
    {
        $finding = (new BeamManifestAudit)->run(false, null, null);
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('missing', $finding->detail);
    }

    public function test_beam_manifest_audit_fails_when_a_required_key_is_absent(): void
    {
        $finding = (new BeamManifestAudit)->run(true, 'numero', null);
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('variant', $finding->detail);
    }

    public function test_mcp_isolation_audit_passes_when_nothing_registered(): void
    {
        $this->assertSame(DoctorStatus::Pass, (new McpIsolationAudit)->run([])->status);
    }

    public function test_mcp_isolation_audit_fails_on_an_unisolated_registration(): void
    {
        $finding = (new McpIsolationAudit)->run([
            ['source' => '.mcp.json', 'command' => 'npx @playwright/mcp@latest'],
        ]);
        $this->assertSame(DoctorStatus::Fail, $finding->status);
    }

    public function test_mcp_isolation_audit_passes_on_an_isolated_registration(): void
    {
        $finding = (new McpIsolationAudit)->run([
            ['source' => '.mcp.json', 'command' => 'npx @playwright/mcp@latest --isolated'],
        ]);
        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_marquee_gate_audit_passes_when_middleware_is_in_the_web_group(): void
    {
        $mw = 'Rushing\\Marquee\\Middleware\\EnforceSiteMode';
        $finding = (new MarqueeGateAudit)->run([$mw], $mw, true, true);
        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_marquee_gate_audit_fails_when_auto_register_on_but_not_wired(): void
    {
        $mw = 'Rushing\\Marquee\\Middleware\\EnforceSiteMode';
        $finding = (new MarqueeGateAudit)->run([], $mw, true, true);
        $this->assertSame(DoctorStatus::Fail, $finding->status);
    }

    public function test_sitemap_audit_warns_when_disabled(): void
    {
        $this->assertSame(DoctorStatus::Warn, (new SitemapReadinessAudit)->run(false, [])->status);
    }

    public function test_sitemap_audit_passes_when_enabled_and_unshadowed(): void
    {
        $this->assertSame(DoctorStatus::Pass, (new SitemapReadinessAudit)->run(true, [])->status);
    }

    public function test_dependency_audit_fails_when_required_marquee_has_no_repo(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            ['require' => ['rushing/laravel-marquee' => 'dev-main']],
            ['packages' => [], 'packages-dev' => []],
        );
        $this->assertSame(DoctorStatus::Fail, $this->finding($findings, 'marquee site-mode gate wired')->status);
    }

    public function test_dependency_audit_warns_on_undeclared_first_party_closure(): void
    {
        $findings = (new BeamDependencyContractAudit)->run(
            ['require' => ['splicewire/laravel-beam' => 'dev-main']],
            ['packages' => [], 'packages-dev' => []],
        );
        $this->assertSame(DoctorStatus::Warn, $this->finding($findings, 'first-party closure declared in repositories')->status);
    }

    // ---- SurgeonWiringAudit: catches surgeon's absence itself (beam-surgeon-rollout #01) --

    public function test_surgeon_wiring_audit_warns_and_never_fails_when_the_host_never_required_it(): void
    {
        $this->pointBaseAt(
            ['minimum-stability' => 'dev', 'prefer-stable' => true],
            ['packages' => [], 'packages-dev' => []],
        );

        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('rushing/laravel-surgeon')
            ->assertExitCode(BeamDoctorCommand::SUCCESS);
    }

    public function test_surgeon_wiring_audit_passes_when_the_host_requires_surgeon(): void
    {
        $this->pointBaseAt(
            ['minimum-stability' => 'dev', 'prefer-stable' => true, 'require' => ['rushing/laravel-surgeon' => '*']],
            ['packages' => [], 'packages-dev' => []],
        );

        $this->artisan('splicewire:beam:doctor')
            ->expectsOutputToContain('discoverable via')
            ->assertExitCode(BeamDoctorCommand::SUCCESS);
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
