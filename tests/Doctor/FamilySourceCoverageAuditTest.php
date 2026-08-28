<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\FamilySourceCoverageAudit;
use Splicewire\Beam\Doctor\FamilyTokenContractAudit;
use Splicewire\Beam\Doctor\Support\FamilyTailwindScan;
use Splicewire\Beam\Tests\TestCase;

class FamilySourceCoverageAuditTest extends TestCase
{
    private FamilyTailwindHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = new FamilyTailwindHost;
    }

    private function finding(): Finding
    {
        $findings = (new FamilySourceCoverageAudit(new FamilyTailwindScan($this->host->root)))->run();

        $this->assertCount(1, $findings);

        return $findings[0];
    }

    public function test_a_host_with_no_tailwind_v4_entry_is_out_of_the_population(): void
    {
        $this->host->package('@schemastud/ui', ['index.js' => 'x']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('out of the audit', $finding->detail);
    }

    /** A v3 host has no `@source` at all — asserting coverage there would be a finding nobody can fix. */
    public function test_a_tailwind_v3_entry_is_not_the_population(): void
    {
        $this->host->write('resources/css/app.css', "@tailwind base;\n@tailwind utilities;\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'x']);

        $this->assertStringContainsString('out of the audit', $this->finding()->detail);
    }

    public function test_a_wired_derivation_plugin_satisfies_the_assertion_with_zero_globs(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n");
        $this->host->write('vite.config.ts', "import { familySources } from '@schemastud/seam/vite';\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'x']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('derived at build time', $finding->detail);
    }

    public function test_a_glob_covering_every_dist_file_passes(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n@source '../../node_modules/@schemastud/ui/dist';\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'x', 'nested/deep.js' => 'y']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('path-matched by 1 declared', $finding->detail);
    }

    /**
     * The audiostud shape, and the reason the assertion PATH-MATCHES files instead of diffing package
     * names: `dist/*.js` reaches every top-level file and nothing in a subdirectory, and a name-keyed
     * set difference calls that covered.
     */
    public function test_a_glob_reaching_most_dist_files_is_still_a_gap(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n@source '../../node_modules/@schemastud/ui/dist/*.js';\n");
        $this->host->package('@schemastud/ui', ['a.js' => 'x', 'b.js' => 'x', 'blockdoc/c.js' => 'y']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('1 of 3 dist files unscanned', $finding->detail);
    }

    public function test_the_finding_carries_a_paste_ready_source_line_per_package(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n");
        $this->host->package('@splicewire/beam-ux', ['index.js' => 'x']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString("@source '../../node_modules/@splicewire/beam-ux/dist';", $finding->detail);
        $this->assertStringContainsString('familySources()', $finding->detail);
    }

    /**
     * The trap that reported every glob dead when every one was live: `ui/src/index.css`'s
     * `../../node_modules` is the APP ROOT, not `ui/`. Resolution is against the CSS FILE's directory.
     */
    public function test_a_globs_prefix_resolves_against_the_css_files_own_directory(): void
    {
        $this->host->write('ui/src/index.css', "@import 'tailwindcss';\n@source '../../node_modules/@schemastud/ui/dist';\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'x']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('path-matched', $finding->detail);
    }

    /** No `dist` is nothing to scan — a self-symlink onto the host's own `ui/` is the usual case. */
    public function test_a_package_without_a_dist_is_not_in_the_population(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n");
        $this->host->package('@splicewire/app-ui', []);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('nothing to scan', $finding->detail);
    }

    /** `@source not '...'` removes coverage; counting it as a glob would certify a gap as covered. */
    public function test_a_negated_source_is_not_coverage(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n@source not '../../node_modules/@schemastud/ui/dist';\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'x']);

        $this->assertSame(DoctorStatus::Fail, $this->finding()->status);
    }

    /**
     * Both audits ride {@see BeamDoctorManifest} — beam is installed at every affected root, and one of
     * them has no surgeon at all — and both are ADVISORY, because "does this host scan this dist" is a
     * fact about the host.
     */
    public function test_both_audits_are_registered_advisory_on_the_beam_doctor_manifest(): void
    {
        $registered = [];

        foreach ($this->app->make(BeamDoctorManifest::class)->registrations() as $registration) {
            $registered[$registration->audit] = $registration;
        }

        foreach ([FamilySourceCoverageAudit::class, FamilyTokenContractAudit::class] as $audit) {
            $this->assertArrayHasKey($audit, $registered, $audit.' is not on the beam doctor manifest.');
            $this->assertFalse($registered[$audit]->gate, $audit.' must never join an exit code.');
            $this->assertSame('splicewire/laravel-beam', $registered[$audit]->package);
        }

        $this->assertLessThan(
            $registered[FamilyTokenContractAudit::class]->order,
            $registered[FamilySourceCoverageAudit::class]->order,
            'the token contract is a tier BELOW coverage and must render after it.',
        );
    }

    /** The manifest resolves each audit argument-free through the container — the whole contract. */
    public function test_each_audit_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(FamilySourceCoverageAudit::class, $this->app->make(FamilySourceCoverageAudit::class));
        $this->assertInstanceOf(FamilyTokenContractAudit::class, $this->app->make(FamilyTokenContractAudit::class));
    }
}
