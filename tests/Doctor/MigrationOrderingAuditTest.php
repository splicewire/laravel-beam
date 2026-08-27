<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\File;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;
use Splicewire\Beam\Doctor\Support\MigrationTableScanner;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * migration-classification-remediation ticket 13.
 *
 * ## What was wrong with this file before
 * Every fixture here was written in a dialect the estate does not use. The reader test fed the audit
 * `Schema::create('silos', function (Blueprint $table) {});` — a shape with **zero instances** across
 * every `~/Workspaces/php/packages/&#42;/&#42;/database/migrations` directory, measured 2026-08-27. So the
 * test was green forever over synthetic input while the audit it covered returned an unconditional
 * `pass` on every real host. A test cannot catch a substrate that no longer exists if its fixtures are
 * the only place that substrate still lives.
 *
 * ## What the fixtures are now
 * The four shapes that actually exist on disk: `ConvergentTable::named('literal')`,
 * `ConvergentTable::named(Beam::table('literal'))`, `->constrained('literal')` and its bare
 * column-derived form, plus the opaque shapes the scanner must keep refusing. The end-to-end cases
 * write REAL migration files into a temp `database/migrations/**` tree, because the whole mechanism is
 * "sort filenames and join" and a mocked filesystem would test the mock.
 */
class MigrationOrderingAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-migration-ordering-'.getmypid().'-'.uniqid();
        File::ensureDirectoryExists($this->root.'/database/migrations/shared');
        File::ensureDirectoryExists($this->root.'/database/migrations/tenant');

        $this->app->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function audit(): MigrationOrderingAudit
    {
        return new MigrationOrderingAudit($this->app, new BeamInstallManifest);
    }

    private function write(string $bucket, string $name, string $body): void
    {
        File::put($this->root.'/database/migrations/'.$bucket.'/'.$name.'.php', "<?php\n\n".$body);
    }

    /**
     * The counters out of a pass detail, as [files, resolved, edges, unresolved].
     *
     * Read as a DELTA against a baseline taken before the fixture is written, never as an absolute:
     * testbench registers beam's own migration paths too, so the population is never just what the test
     * put there — and a test asserting an absolute count would break every time a stub is added.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function counters(): array
    {
        $findings = $this->audit()->run();

        preg_match_all('/(\d+) (?:file|table name|dependency edge|name)\(s\)/', $findings[0]->detail, $m);

        return array_map('intval', array_slice($m[1], 0, 4));
    }

    // ---------------------------------------------------------------------------------------------
    // The scanner: the four shapes that exist, and the ones it must stay quiet about.
    // ---------------------------------------------------------------------------------------------

    /** The estate's actual create dialect since the 2026-08-18 convergence sweep. */
    public function test_it_reads_a_convergent_create_with_a_literal_name(): void
    {
        $refs = MigrationTableScanner::creates(<<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('silos')->define(function (Blueprint $table): void {})->assert();
        PHP);

        $this->assertCount(1, $refs);
        $this->assertSame('silos', $refs[0]->name);
        $this->assertFalse($refs[0]->prefixed);
        $this->assertSame('ConvergentTable::named', $refs[0]->shape);
    }

    /**
     * The half a regex extension can never recover. `Beam::table('hooks')` is not unresolvable — it is
     * unresolvable *from source*, so the scanner hands the literal up flagged and the audit, which runs
     * in a booted host, asks the facade. That split is what the rewrite bought.
     */
    public function test_it_reads_a_prefixed_convergent_create_without_resolving_it(): void
    {
        $refs = MigrationTableScanner::creates(<<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Rushing\SchemaConvergence\ConvergentTable;
        use Splicewire\Beam\Facades\Beam;

        ConvergentTable::named(Beam::table('hooks'))->define(function (Blueprint $table): void {})->assert();
        PHP);

        $this->assertCount(1, $refs);
        $this->assertSame('hooks', $refs[0]->name);
        $this->assertTrue($refs[0]->prefixed);
    }

    /** The channel that carries the live defect: a constraint cannot be topped up, so order decides it. */
    public function test_it_reads_a_literal_foreign_key_target(): void
    {
        $refs = MigrationTableScanner::references(<<<'PHP'
        <?php

        $table->foreignUuid('silo_id')->nullable()->constrained('silos')->nullOnDelete();
        $table->foreignId('owner_id')->references('id')->on('users');
        PHP);

        $this->assertSame(['silos', 'users'], array_map(fn ($ref) => $ref->name, $refs));
    }

    /**
     * `->constrained()` with no argument is not a guess: Laravel derives the table from the column, and
     * this copies that derivation rather than inventing one. 8 live edges in the estate are this shape.
     */
    public function test_it_derives_a_bare_constrained_target_from_the_column(): void
    {
        $refs = MigrationTableScanner::references(<<<'PHP'
        <?php

        $table->foreignUuid('silo_id')->nullable()->constrained()->nullOnDelete();
        PHP);

        $this->assertCount(1, $refs);
        $this->assertSame('silos', $refs[0]->name);
        $this->assertSame('column', $refs[0]->via);
    }

    /** A prefixed FK target, which the audit resolves through the container exactly like a create. */
    public function test_it_reads_a_prefixed_foreign_key_target(): void
    {
        $refs = MigrationTableScanner::references(<<<'PHP'
        <?php

        use Splicewire\Beam\Facades\Beam;

        $table->foreignId('seller_id')->constrained(Beam::table('market_sellers'));
        PHP);

        $this->assertCount(1, $refs);
        $this->assertSame('market_sellers', $refs[0]->name);
        $this->assertTrue($refs[0]->prefixed);
    }

    /**
     * The refusal, kept verbatim from the original audit because it was always the right posture. These
     * are the estate's three live opaque shapes; 39 creates are named this way and every one of them
     * must go quiet rather than produce a confident warning about a table that exists under some other
     * name.
     */
    public function test_it_refuses_to_guess_an_opaque_name(): void
    {
        $refs = MigrationTableScanner::creates(<<<'PHP'
        <?php

        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named($this->target())->assert();
        ConvergentTable::named($tableNames['roles'])->assert();
        ConvergentTable::named($lunarPrefix.'products')->assert();
        PHP);

        $this->assertCount(3, $refs);

        foreach ($refs as $ref) {
            $this->assertTrue($ref->isOpaque());
            $this->assertSame('opaque', $ref->via);
        }
    }

    /**
     * A convergent create is deliberately NOT an alter. Convergence makes create and top-up the same
     * call, so a convergent stub stamped ahead of "its" create simply becomes the create — the shape the
     * old audit modelled cannot fail that way any more. Only a bare `Schema::table()` still requires the
     * table to already exist.
     */
    public function test_only_an_unguarded_schema_table_counts_as_an_alter(): void
    {
        $source = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;
        use Rushing\SchemaConvergence\ConvergentTable;

        Schema::table('fragments', function () {});
        ConvergentTable::named('silos')->assert();
        PHP;

        $this->assertSame(['fragments'], array_map(fn ($ref) => $ref->name, MigrationTableScanner::alters($source)));
        $this->assertSame(['silos'], array_map(fn ($ref) => $ref->name, MigrationTableScanner::creates($source)));
    }

    /** Prose about a create is not a create — the reason this is tokens rather than a regex. */
    public function test_it_ignores_a_create_named_only_in_a_docblock(): void
    {
        $this->assertSame([], MigrationTableScanner::creates(<<<'PHP'
        <?php

        use Rushing\SchemaConvergence\ConvergentTable;

        /** This used to be ConvergentTable::named('legacy_silos') before the squash. */
        PHP));
    }

    // ---------------------------------------------------------------------------------------------
    // The audit: the join, against real files on disk.
    // ---------------------------------------------------------------------------------------------

    /**
     * THE SHAPE THAT BROKE `entities`, reproduced. Measured 2026-08-27 at `~/Herd/splicewire-app`:
     * `tenant/…_create_entities_table` carries `->constrained('silos')` and sorted BEFORE
     * `shared/…_create_silos_table`, so the unqualified reference fell through a search_path widened to
     * `"$db,public"` and bound to `public.silos` while the writer writes `tenant_system.silos`.
     *
     * Both files are convergent CREATEs, which is why the old audit — which modelled only
     * ALTER-after-CREATE, and could not see a convergent create at all — reported `pass` on it twice
     * over.
     */
    public function test_it_flags_a_create_whose_foreign_key_targets_a_table_created_later(): void
    {
        $this->write('tenant', '2026_08_11_203210_create_entities_table', <<<'PHP'
        use Illuminate\Database\Schema\Blueprint;
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('entities')
            ->define(function (Blueprint $table): void {
                $table->foreignUuid('silo_id')->nullable()->constrained('silos')->nullOnDelete();
            })
            ->assert();
        PHP);

        $this->write('shared', '2026_08_17_180016_create_silos_table', <<<'PHP'
        use Illuminate\Database\Schema\Blueprint;
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('silos')->define(function (Blueprint $table): void {})->assert();
        PHP);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('create_entities_table', $findings[0]->detail);
        $this->assertStringContainsString('silos', $findings[0]->detail);
        $this->assertStringContainsString('create_silos_table', $findings[0]->detail);
    }

    /** The same pair, correctly stamped — the state the flagship is in today. */
    public function test_it_passes_when_the_create_lands_first(): void
    {
        $this->write('shared', '2026_08_27_190049_create_silos_table', <<<'PHP'
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('silos')->assert();
        PHP);

        $this->write('tenant', '2026_08_27_190059_create_entities_table', <<<'PHP'
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('entities')->define(function ($table) {
            $table->foreignUuid('silo_id')->constrained('silos');
        })->assert();
        PHP);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /**
     * `database/migrations/tenant` is registered by the tenancy layer's own `--path`, never with the
     * migrator, so `MigrationFiles::pathsFor()` alone cannot see it — and it is one HALF of the defect
     * above. An audit that enumerated only the migrator's paths would report a clean pass on the exact
     * case it exists to catch.
     */
    public function test_it_enumerates_published_subdirectories_the_migrator_does_not_register(): void
    {
        [$files, $resolved] = $this->counters();

        $this->write('tenant', '2026_01_01_000000_create_entities_table', <<<'PHP'
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('entities')->assert();
        PHP);

        [$after, $resolvedAfter] = $this->counters();

        $this->assertSame($files + 1, $after);
        $this->assertSame($resolved + 1, $resolvedAfter);
    }

    /**
     * The pass detail must carry its own coverage, so silence cannot be read as a clean bill of health.
     * This is the guard against the defect that produced this ticket: the old audit's `pass` said
     * "every cross-package ALTER installs after the package that creates its table" while reading
     * nothing at all.
     */
    public function test_the_pass_reports_what_it_could_not_read(): void
    {
        [, , , $unresolved] = $this->counters();

        $this->write('shared', '2026_01_01_000000_create_things_table', <<<'PHP'
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named($this->target())->assert();
        PHP);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame($unresolved + 1, $this->counters()[3]);
        $this->assertStringContainsString('not a claim of full coverage', $findings[0]->detail);
    }

    /** A reference to a table nothing in this host creates is not this audit's business. */
    public function test_it_stays_silent_about_a_table_no_migration_here_creates(): void
    {
        $this->write('shared', '2026_01_01_000000_create_things_table', <<<'PHP'
        use Rushing\SchemaConvergence\ConvergentTable;

        ConvergentTable::named('things')->define(function ($table) {
            $table->foreignId('account_id')->constrained('accounts');
        })->assert();
        PHP);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /** Advisory, never fatal: an empty host is a pass, not an exception. */
    public function test_it_passes_on_a_host_with_no_migrations(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }
}
