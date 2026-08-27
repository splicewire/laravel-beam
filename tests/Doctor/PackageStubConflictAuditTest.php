<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\PackageStubConflictAudit;
use Splicewire\Beam\Install\PackageStubs;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 182 — a package stub rehearsed against the LIVE database, which is the one scoping
 * the estate had no instrument for.
 *
 * The fixture is a real fake host: a `vendor/composer/installed.json`, a package directory holding a
 * real `.php.stub`, and a real table in the test schema. All three are load-bearing. The mechanism IS
 * composer's manifest, a glob, a `require` of an UNPUBLISHED file and a live `getColumns()`, so mocking
 * any of them would test the mock — the same reasoning `ConvergencePreflightTest` gives for its fixtures.
 *
 * The case the class exists for is `test_it_reports_a_stub_that_would_throw_if_it_were_published`: the
 * stub is not published anywhere, never will be while the host's own copy holds the stem, and is
 * therefore invisible to both of the audits that already existed.
 */
class PackageStubConflictAuditTest extends TestCase
{
    private string $root;

    private string $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-package-stub-conflict-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->package = $this->root.'/vendor/acme/widgets';

        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->package.'/database/migrations');
        File::ensureDirectoryExists($this->root.'/vendor/composer');

        File::put($this->root.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'acme/widgets', 'install-path' => '../acme/widgets'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        Schema::dropIfExists('widgets');

        parent::tearDown();
    }

    private function audit(): array
    {
        return (new PackageStubConflictAudit($this->root))->run();
    }

    private function stub(string $name, string $body): void
    {
        File::put($this->package.'/database/migrations/'.$name, <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;
        use Rushing\\SchemaConvergence\\ConvergentTable;

        {$body}
        PHP);
    }

    /** A convergent template declaring `widgets.name` as a string — the shape a package publishes. */
    private function widgets(): void
    {
        $this->stub('create_widgets_table.php.stub', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')
                    ->define(function (Blueprint $table) {
                        $table->uuid('id')->primary();
                        $table->string('name');
                    })
                    ->assert();
            }

            public function down(): void
            {
                Schema::dropIfExists('widgets');
            }
        };
        PHP);
    }

    public function test_a_host_installing_no_convergent_template_passes_and_names_the_other_two_audits(): void
    {
        // Written without the shared header on purpose: `isConvergent()` keys on the guard's NAME
        // appearing in the source, so a fixture that imports it is in the population whatever its body.
        File::put(
            $this->package.'/database/migrations/create_plain_table.php.stub',
            "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration {};\n",
        );

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('beam.schema.unrehearsable-stub', $findings[0]->detail);
        $this->assertStringContainsString('beam.schema.unguarded-create', $findings[0]->detail);
    }

    public function test_a_template_whose_table_is_absent_is_not_a_finding(): void
    {
        // Convergence CREATES what is missing. A divergence that would not conflict is not a hazard, and
        // reporting it is how this audit would acquire the drift audit's 314-copy noise floor.
        $this->widgets();

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 package migration template(s)', $findings[0]->detail);
    }

    public function test_a_template_that_converges_onto_the_live_table_is_not_a_finding(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        $this->widgets();

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_it_reports_a_stub_that_would_throw_if_it_were_published(): void
    {
        // The host arrived with an integer `name` — its own deliberate shape, and NOT published from this
        // stub. Nothing here is to be repaired; the point is that republishing the stub would throw and
        // no audit in the estate said so.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('name');
        });

        $this->widgets();

        $findings = $this->audit();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 column(s) across 1 package migration template(s)', $findings[0]->detail);

        // The hazard, not the divergence — the finding names the republish that fails and both types.
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('Republishing `create_widgets_table` (acme/widgets)', $findings[1]->detail);
        $this->assertStringContainsString('widgets.name', $findings[1]->detail);
        $this->assertStringContainsString('declares `string`', $findings[1]->detail);
    }

    public function test_an_unrehearsable_template_is_skipped_and_the_check_says_it_verified_nothing(): void
    {
        // RehearsalSafety refuses this body and MUST keep refusing it: the raw statement would run for
        // real during a pass that promised to write nothing. With nothing else to rehearse, an advisory
        // that verified nothing reports Warn — a pass here would be a check that stopped gating.
        $this->stub('create_particles_table.php.stub', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('particles')->define(fn (Blueprint $t) => $t->uuid('id'))->assert();
                DB::statement('CREATE UNIQUE INDEX particles_idx ON particles (id)');
            }
        };
        PHP);

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('verified NOTHING', $findings[0]->detail);
    }

    public function test_the_skipped_count_is_stated_beside_a_pass_so_a_green_is_not_read_as_coverage(): void
    {
        $this->widgets();
        $this->stub('create_particles_table.php.stub', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('particles')->define(fn (Blueprint $t) => $t->uuid('id'))->assert();
                DB::statement('CREATE UNIQUE INDEX particles_idx ON particles (id)');
            }
        };
        PHP);

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 more could not be asked', $findings[0]->detail);
    }

    public function test_the_population_is_package_templates_resolved_through_composers_own_record(): void
    {
        $this->widgets();

        $stubs = PackageStubs::forHost($this->root);

        $this->assertCount(1, $stubs);
        $this->assertSame('acme/widgets', $stubs[0]['package']);
        $this->assertSame('create_widgets_table', $stubs[0]['stem']);

        // A published copy in the HOST's own tree is the other audit's population and never this one's.
        File::ensureDirectoryExists($this->root.'/database/migrations');
        File::put($this->root.'/database/migrations/2026_01_01_000000_create_widgets_table.php', '<?php');

        $this->assertCount(1, PackageStubs::forHost($this->root));
    }

    public function test_a_root_with_no_composer_manifest_reports_no_population(): void
    {
        // A package repo running its own testbench has no host manifest, and reporting nothing is right.
        File::delete($this->root.'/vendor/composer/installed.json');

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('No installed package ships a convergent migration template', $findings[0]->detail);
    }
}
