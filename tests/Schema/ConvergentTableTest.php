<?php

namespace Splicewire\Beam\Tests\Schema;

use BadMethodCallException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Schema\ConvergentTable;
use Splicewire\Beam\Schema\SchemaConflict;
use Splicewire\Beam\Schema\SchemaConvergenceConflict;
use Splicewire\Beam\Tests\TestCase;

/**
 * The three tiers, both terminals, and the two limits convergence cannot reach.
 *
 * The tier-three cases are the point of the whole utility: they are the ones a bare
 * `Schema::hasTable($t) → return` guard reports SUCCESS for while leaving the app on a schema it cannot
 * use (beam-facade tickets 13, 16/17, 20/23). Each is written as the collision that actually happened,
 * not as an abstract type mismatch — a bigint morph id where beam declares a string is lunarphp's
 * `activity_log`, and NOT-NULL residue under a shared name is the submissions fork.
 */
class ConvergentTableTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');

        parent::tearDown();
    }

    // Tier 1 — absent.

    public function test_it_creates_the_table_when_it_is_absent(): void
    {
        $report = ConvergentTable::named('widgets')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
            })
            ->assert();

        $this->assertTrue($report->created);
        $this->assertTrue(Schema::hasTable('widgets'));
        $this->assertTrue(Schema::hasColumns('widgets', ['id', 'name']));
    }

    // Tier 2 — present, columns missing.

    public function test_it_adds_missing_columns_to_a_table_someone_else_created(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        $report = $this->declaration()->assert();

        $this->assertFalse($report->created);
        $this->assertSame(['name', 'payload'], $report->addedColumns);
        $this->assertTrue(Schema::hasColumns('widgets', ['id', 'name', 'payload']));
    }

    public function test_a_table_that_already_matches_is_left_alone(): void
    {
        $this->declaration()->assert();

        $report = $this->declaration()->assert();

        $this->assertTrue($report->unchanged());
        $this->assertTrue($report->converged());
        $this->assertSame('`widgets` already matches', $report->summary());
    }

    public function test_it_converges_a_declared_index_the_live_table_lacks(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
        });

        $report = ConvergentTable::named('widgets')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('payload')->nullable();
                $table->unique(['name'], 'widgets_name_unique');
            })
            ->assert();

        $this->assertSame(['widgets_name_unique'], $report->addedIndexes);
        $this->assertTrue(Schema::hasIndex('widgets', 'widgets_name_unique'));
    }

    public function test_a_fluent_index_on_an_added_column_arrives_with_the_column(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        ConvergentTable::named('widgets')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name')->index();
            })
            ->assert();

        $this->assertTrue(Schema::hasIndex('widgets', ['name']));
    }

    // Tier 3 — present, wrong type. The silent-wrong-schema class.

    public function test_it_throws_when_a_column_exists_with_an_incompatible_type(): void
    {
        // lunarphp's `activity_log` shape: bigint morph ids where beam declares strings.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $this->expectException(SchemaConvergenceConflict::class);
        $this->expectExceptionMessageMatches('/widgets\.name/');

        $this->declaration()->assert();
    }

    public function test_a_conflicted_run_writes_nothing_at_all(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $this->assertFalse($this->declaration()->matches());

        // `payload` was missing and legitimately addable — but a conflict elsewhere in the table means
        // the run is a no-op, so the two terminals never differ in what they did.
        $this->assertFalse(Schema::hasColumn('widgets', 'payload'));
    }

    public function test_matches_reports_the_conflict_without_throwing(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $this->assertFalse($this->declaration()->matches());
        $this->assertTrue(Schema::hasTable('widgets'));
    }

    public function test_matches_is_true_when_the_table_converges(): void
    {
        $this->assertTrue($this->declaration()->matches());
        $this->assertTrue($this->declaration()->matches());
    }

    public function test_an_unmappable_declared_type_is_reported_unverified_never_conflicted(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        $report = ConvergentTable::named('widgets')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('name', ['a', 'b']);
            })
            ->assert();

        $this->assertSame(['name'], $report->unverified);
        $this->assertTrue($report->converged());
    }

    // The two limits convergence cannot reach (ticket 23).

    public function test_a_required_column_cannot_be_added_to_a_populated_table(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        DB::table('widgets')->insert(['id' => '00000000-0000-0000-0000-000000000001']);

        try {
            $this->declaration()->assert();
            $this->fail('Expected a required-addition conflict.');
        } catch (SchemaConvergenceConflict $exception) {
            $this->assertSame(
                [SchemaConflict::REQUIRED_ADDITION],
                array_values(array_unique(array_map(fn ($c) => $c->kind, $exception->report->conflicts))),
            );
        }

        // The same declaration against an EMPTY table converges: it is the rows, not the column.
        DB::table('widgets')->delete();

        $this->assertTrue($this->declaration()->matches());
    }

    public function test_not_null_residue_the_declaration_does_not_know_about_is_a_conflict(): void
    {
        // The submissions fork: a different table wearing the same name, whose own NOT NULL column
        // survives a textbook-clean convergence and then rejects every canonical insert.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
            $table->string('schema_record_id');
        });

        try {
            $this->declaration()->assert();
            $this->fail('Expected a required-residue conflict.');
        } catch (SchemaConvergenceConflict $exception) {
            $this->assertCount(1, $exception->report->conflicts);
            $this->assertSame(SchemaConflict::REQUIRED_RESIDUE, $exception->report->conflicts[0]->kind);
            $this->assertSame('schema_record_id', $exception->report->conflicts[0]->column);
        }
    }

    public function test_ignoring_columns_exempts_residue_a_host_fills_deliberately(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
            $table->string('schema_record_id');
        });

        $this->assertTrue($this->declaration()->ignoringColumns('schema_record_id')->matches());
    }

    public function test_a_nullable_or_defaulted_extra_column_is_not_residue(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
            $table->string('added_by_a_host')->nullable();
            $table->string('with_a_default')->default('x');
        });

        $this->assertTrue($this->declaration()->matches());
    }

    // The escape hatches.

    public function test_exists_using_overrides_the_presence_question(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        // A substrate where hasTable() is the wrong question answers "absent" and gets a create —
        // which here fails loudly rather than silently converging, proving the predicate was used.
        $this->expectExceptionMessageMatches('/widgets/');

        ConvergentTable::named('widgets')
            ->existsUsing(fn () => false)
            ->define(fn (Blueprint $table) => $table->uuid('id')->primary())
            ->assert();
    }

    public function test_it_is_macroable_for_the_conversion_extension_point(): void
    {
        ConvergentTable::macro('assertOrLog', fn () => 'macro:'.$this->table);

        $this->assertSame('macro:widgets', ConvergentTable::named('widgets')->assertOrLog());
    }

    public function test_a_terminal_without_a_definition_is_a_programming_error(): void
    {
        $this->expectException(BadMethodCallException::class);

        ConvergentTable::named('widgets')->assert();
    }

    /** The declaration under test: two columns beyond a bare `id`, one required and one not. */
    protected function declaration(): ConvergentTable
    {
        return ConvergentTable::named('widgets')->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
        });
    }
}
