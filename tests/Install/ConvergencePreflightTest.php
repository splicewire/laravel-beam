<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\SchemaConflict;
use Splicewire\Beam\Install\ConvergencePreflight;
use Splicewire\Beam\Tests\TestCase;

/**
 * The convergence preflight (beam-facade ticket 84): every pending convergent stub asked what it would
 * do, before `migrate` does any of it.
 *
 * Fixtures are real migration files on disk and a real sqlite schema, for the same reason the ownership
 * pass's are: the mechanism IS a glob, a `require` and a live `getColumns()`, so mocking any of the three
 * would test the mock. The case the whole class exists for is the last one — a conflict reported with
 * nothing written and the rest of the queue still measured, which is exactly what `migrate` cannot do.
 */
class ConvergencePreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-convergence-preflight-'.getmypid();
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/migrations');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        Schema::dropIfExists('widgets');
        Schema::dropIfExists('gadgets');

        parent::tearDown();
    }

    private function preflight(?array $ran = []): ConvergencePreflight
    {
        return new ConvergencePreflight([$this->root.'/migrations'], $ran);
    }

    /** A convergent stub declaring `widgets` with `name` as a string. */
    private function widgets(string $name = '2026_08_24_000001_create_widgets_table.php'): string
    {
        return $this->write($name, <<<'PHP'
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

    private function write(string $name, string $body): string
    {
        $header = "<?php\n\nuse Illuminate\Database\Migrations\Migration;\n".
            "use Illuminate\Database\Schema\Blueprint;\n".
            "use Illuminate\Support\Facades\DB;\n".
            "use Illuminate\Support\Facades\Schema;\n".
            "use Rushing\SchemaConvergence\ConvergentTable;\n\n";

        File::put($path = $this->root.'/migrations/'.$name, $header.$body."\n");

        return $path;
    }

    public function test_it_predicts_a_create_without_creating_anything(): void
    {
        $this->widgets();

        $found = $this->preflight()->rehearse();

        $this->assertCount(1, $found);
        $this->assertTrue($found[0]->wasRehearsed());
        $this->assertTrue($found[0]->reports[0]->created);
        $this->assertFalse($found[0]->reports[0]->applied);
        $this->assertFalse(Schema::hasTable('widgets'));
    }

    /**
     * The failure the ticket was filed about: an operator three tables in, a stack trace, and ~30 more
     * migrations they never learned anything about. Here BOTH are measured — the conflict and the stub
     * behind it — and neither is written.
     */
    public function test_a_conflict_does_not_stop_the_rest_of_the_queue_from_being_measured(): void
    {
        // The live `tags` shape at ~/Herd/audiostud: spatie's integer key where beam-taxonomy declares uuid.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $this->widgets();
        $this->write('2026_08_24_000002_create_gadgets_table.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('gadgets')
                    ->define(fn (Blueprint $table) => $table->uuid('id')->primary())
                    ->assert();
            }

            public function down(): void
            {
                Schema::dropIfExists('gadgets');
            }
        };
        PHP);

        $found = $this->preflight()->rehearse();

        $this->assertCount(2, $found);
        $this->assertTrue($found[0]->hasConflicts());
        $this->assertSame(SchemaConflict::TYPE, $found[0]->conflicts()[0]->kind);
        $this->assertSame('2026_08_24_000001_create_widgets_table', $found[0]->migration);

        // The one `migrate` never reaches, because it dies on the conflict above.
        $this->assertFalse($found[1]->hasConflicts());
        $this->assertTrue($found[1]->reports[0]->created);

        $this->assertFalse(Schema::hasTable('gadgets'));
        $this->assertFalse(Schema::hasColumn('widgets', 'payload'));
    }

    public function test_a_conflict_carries_the_repair_the_operator_was_previously_left_to_work_out(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $this->widgets();

        $conflict = $this->preflight()->rehearse()[0]->conflicts()[0];

        $this->assertStringContainsString("Schema::table('widgets'", $conflict->repair);
        $this->assertStringContainsString('->change();', $conflict->repair);
    }

    public function test_a_migration_already_in_the_ledger_is_not_rehearsed(): void
    {
        $this->widgets();

        $this->assertSame([], $this->preflight(['2026_08_24_000001_create_widgets_table'])->rehearse());
    }

    /** No ledger to read (a fresh host, or a database this command cannot reach) ⇒ everything is pending. */
    public function test_an_unknown_ledger_treats_every_migration_as_pending(): void
    {
        $this->widgets();

        $this->assertCount(1, $this->preflight(null)->rehearse());
    }

    /**
     * The candidacy predicate is the source naming `ConvergentTable` at all — so an ordinary ALTER
     * migration is invisible here rather than being reported as unrehearsable. Written without the
     * fixture header on purpose: an import alone satisfies the predicate, which is the same
     * source-text-reads-what-the-file-says-about-itself limit beam-facade ticket 77 recorded.
     */
    public function test_a_migration_with_no_convergent_guard_is_not_a_candidate(): void
    {
        File::put($this->root.'/migrations/2026_08_24_000003_add_a_column.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('widgets', fn (Blueprint $table) => $table->string('extra')->nullable());
            }

            public function down(): void {}
        };
        PHP);

        $this->assertSame([], $this->preflight()->rehearse());
    }

    /**
     * Rehearsal neutralises convergent guards and NOTHING ELSE, so a body carrying anything that writes
     * is reported rather than run. Reported, never dropped: a preflight that silently skipped its one
     * risky migration would read as "everything is clean", which is the false-green shape the guard
     * itself exists to end.
     */
    public function test_a_body_that_writes_beside_the_guard_is_skipped_and_said(): void
    {
        $this->write('2026_08_24_000004_create_widgets_and_seed_it.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')
                    ->define(fn (Blueprint $table) => $table->uuid('id')->primary())
                    ->assert();

                DB::statement('create table side_effect (id integer)');
            }

            public function down(): void
            {
                Schema::dropIfExists('widgets');
            }
        };
        PHP);

        $found = $this->preflight()->rehearse();

        $this->assertCount(1, $found);
        $this->assertFalse($found[0]->wasRehearsed());
        $this->assertStringContainsString('raw database statement', (string) $found[0]->skipped);
        $this->assertFalse(Schema::hasTable('side_effect'));
    }

    /**
     * The scan runs over everything EXCEPT `down()`, which is the stricter reading and the one that
     * matters: beam's own `create_activity_log_table` resolves its presence question through a private
     * helper, so an `up()`-only scan would read a short body and miss whatever it calls.
     */
    public function test_the_scan_reaches_a_private_helper_and_ignores_the_down_method(): void
    {
        $this->write('2026_08_24_000005_create_widgets_via_helper.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')
                    ->define(fn (Blueprint $table) => $table->uuid('id')->primary())
                    ->assert();

                $this->backfill();
            }

            public function down(): void
            {
                Schema::dropIfExists('widgets');
                Schema::dropIfExists('gadgets');
            }

            private function backfill(): void
            {
                DB::table('widgets')->insert(['id' => 'x']);
            }
        };
        PHP);

        $found = $this->preflight()->rehearse();

        $this->assertFalse($found[0]->wasRehearsed());
        $this->assertStringContainsString('row write', (string) $found[0]->skipped);
    }

    /** A `down()` full of drops is exactly what belongs there, and never runs from a preflight. */
    public function test_a_stub_whose_only_writes_are_in_down_is_rehearsed_normally(): void
    {
        $this->widgets();

        $this->assertTrue($this->preflight()->rehearse()[0]->wasRehearsed());
    }

    /**
     * The `require` trap: PHP returns `true` rather than the migration object on a second include of the
     * same path, and `migrate` runs in THIS process a few lines later through the same
     * `Migrator::resolvePath()`. Requiring the real file here would hand the migrator a boolean and break
     * the install this pass exists to protect — so the file is copied first, and the proof is that the
     * real path is still resolvable afterwards.
     */
    public function test_rehearsing_leaves_the_real_file_requirable_by_the_migrator(): void
    {
        $path = $this->widgets();

        $this->preflight()->rehearse();

        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, require $path);
    }

    /** A body that blows up is a finding, not a crash — a fresh host that cannot reach a database installs. */
    public function test_a_body_that_throws_is_reported_rather_than_propagated(): void
    {
        $this->write('2026_08_24_000006_create_widgets_but_explode.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')->define(fn (Blueprint $t) => $t->uuid('id'))->assert();

                throw new RuntimeException('no database here');
            }

            public function down(): void {}
        };
        PHP);

        $found = $this->preflight()->rehearse();

        $this->assertFalse($found[0]->wasRehearsed());
        $this->assertStringContainsString('no database here', (string) $found[0]->skipped);
    }
}
