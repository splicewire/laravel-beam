<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * The audit joins the install manifest against every registered package's migration stubs. Under
 * testbench only beam itself is manifest-registered and installed, so these exercise the join logic
 * through the real class with a hand-built manifest rather than asserting on the estate's live graph
 * — that assertion belongs in the consuming app, where the whole stack boots.
 */
class MigrationOrderingAuditTest extends TestCase
{
    public function test_it_passes_when_nothing_registered_alters_another_packages_table(): void
    {
        $findings = (new MigrationOrderingAudit(new BeamInstallManifest))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_it_reads_created_and_altered_tables_from_a_stub(): void
    {
        $audit = new MigrationOrderingAudit(new BeamInstallManifest);

        $method = new \ReflectionMethod($audit, 'tablesIn');

        [$created, $altered] = $method->invoke($audit, <<<'PHP'
            Schema::create('silos', function (Blueprint $table) {});
            Schema::table('fragments', function (Blueprint $table) {});
            PHP);

        $this->assertSame(['silos'], $created);
        $this->assertSame(['fragments'], $altered);
    }

    /**
     * A dynamically-named target is unresolvable without booting the declaring package. The audit must
     * stay silent rather than guess — a confident warning about a table that does not exist under that
     * name is worse than no warning.
     */
    public function test_it_refuses_to_guess_a_dynamically_named_table(): void
    {
        $audit = new MigrationOrderingAudit(new BeamInstallManifest);

        $method = new \ReflectionMethod($audit, 'tablesIn');

        [$created, $altered] = $method->invoke($audit, <<<'PHP'
            Schema::create($this->target(), function (Blueprint $table) {});
            Schema::table(Beam::table('media'), function (Blueprint $table) {});
            PHP);

        $this->assertSame([], $created);
        $this->assertSame([], $altered);
    }

    public function test_it_strips_the_human_label_from_beam_cores_package_name(): void
    {
        $audit = new MigrationOrderingAudit(new BeamInstallManifest);

        $method = new \ReflectionMethod($audit, 'installPath');

        // beam-core registers itself as 'splicewire/laravel-beam (core)'; the composer name is clean,
        // so the label must not leak into the lookup or every core stub goes unscanned.
        $this->assertNotNull($method->invoke($audit, 'splicewire/laravel-beam (core)'));
    }
}
