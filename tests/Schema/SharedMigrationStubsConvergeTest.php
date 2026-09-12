<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\SchemaConvergence\ColumnTypeEquivalence;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Tests\TestCase;

/**
 * The worked proof for `rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md`: every one of beam's
 * published migration stubs, run TWICE each, on the real schema builder.
 *
 * This is the acceptance the convention actually needs, and it is not the same claim as
 * {@see ConvergentTableTest}. That one exercises the tiers against a synthetic table; this one asserts
 * every declaration beam ships is convergent against ITSELF — that a second pass over a table the
 * first pass created finds nothing to do and nothing to complain about.
 *
 * The second-pass assertion is where {@see ColumnTypeEquivalence} is really under
 * test: every declared type in these files is compiled by the driver, read back through
 * `Schema::getColumns()`, and compared. A gap in the map surfaces here as a false conflict rather than
 * as a silent nothing, which is why the run asserts `unchanged()` and not merely "no exception".
 *
 * `$tables` is a closed allowlist, not derived from {@see stubs()}'s glob — a NEW stub must add its
 * table name here too, or its own `test_the_stub_creates_then_converges_onto_itself` case fails with
 * "created none of the tables this test knows about" (found live, adding `create_beam_git_repos_table`
 * without updating this list first).
 */
class SharedMigrationStubsConvergeTest extends TestCase
{
    /** @var list<string> */
    protected array $tables = [
        'beam_particles',
        'beam_versions',
        'beam_submissions',
        'beam_ownership_edges',
        'beam_schemas',
        'beam_git_repos',
        'activity_log',
        // api-surface-coherence 38. `beam_hooks` under the default prefix — the RESOURCE key is `hooks`
        // (that is what the route and the particle stamp say), but the TABLE routes through
        // `Beam::table()` like every other core table, so a retrofit host's one prefix override follows
        // here too. The two names are deliberately not the same string.
        'beam_hooks',
    ];

    protected function tearDown(): void
    {
        foreach ([...$this->tables, 'schema_registry'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * The CREATE stubs — every shipped stub whose job is to bring a table into existence.
     *
     * ⚠️ Not every shared stub is one. A type change cannot be made by editing a create (that edit
     * reaches only a fresh database) and cannot be made by the convergent guard either (it throws on
     * a present column of the wrong type rather than converting it), so the estate's repair for one
     * is a separately stamped ALTER shipped in this same directory — `widen_beam_hook_morph_ids_to_string`
     * is the first. An ALTER creates no table and carries no `ConvergentTable`, so it fails both
     * assertions below for reasons that are the point rather than a defect. {@see alterStubs()}
     * holds them to their own, stricter contract instead.
     *
     * The split is by FILENAME, and deliberately so: `create_*` is what package-tools, the doctor's
     * publish-gate coverage and the migration-ordering audit all key on, so a stub that creates a
     * table and is not named `create_*` is already broken elsewhere.
     *
     * @return array<string, array{string}>
     */
    public static function stubs(): array
    {
        $cases = [];

        foreach (static::allStubs() as $name => $path) {
            if (str_starts_with($name, 'create_')) {
                $cases[$name] = [$path];
            }
        }

        return $cases;
    }

    /**
     * The ALTER stubs — everything in `shared/` that is not a create.
     *
     * @return array<string, array{string}>
     */
    public static function alterStubs(): array
    {
        $cases = [];

        foreach (static::allStubs() as $name => $path) {
            if (! str_starts_with($name, 'create_')) {
                $cases[$name] = [$path];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, string>
     */
    protected static function allStubs(): array
    {
        $directory = dirname(__DIR__, 2).'/database/migrations/shared';

        $cases = [];

        foreach (glob($directory.'/*.php.stub') ?: [] as $path) {
            $cases[basename($path, '.php.stub')] = $path;
        }

        return $cases;
    }

    #[DataProvider('stubs')]
    public function test_the_stub_creates_then_converges_onto_itself(string $path): void
    {
        $this->migration($path)->up();

        $created = $this->snapshot();
        $this->assertNotSame([], $created, 'The stub created none of the tables this test knows about.');

        // Second pass over the table the first pass created. Convergent means a no-op — not a second
        // create, not a conflict, and not a stray column or index topped up onto its own output.
        $this->migration($path)->up();

        $this->assertSame($created, $this->snapshot());
    }

    public function test_every_shipped_stub_carries_a_convergent_guard(): void
    {
        foreach (static::stubs() as $name => [$path]) {
            $this->assertStringContainsString(
                ConvergentTable::class,
                (string) file_get_contents($path),
                "`{$name}` does not import the convergent guard — a bare `Schema::create` here is the "
                .'shape the convention exists to end.',
            );
        }
    }

    public function test_the_shipped_stubs_together_produce_the_expected_tables(): void
    {
        foreach (static::stubs() as [$path]) {
            $this->migration($path)->up();
        }

        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "`{$table}` was not created.");
        }
    }

    /**
     * An ALTER must survive the host it was never needed at. A host whose beam install predates the
     * table it repairs has nothing to alter, and the only acceptable behaviour is silence — a throw
     * here fails `migrate` on an install that is not broken, which is strictly worse than the defect
     * the ALTER exists to fix.
     */
    #[DataProvider('alterStubs')]
    public function test_an_alter_stub_is_silent_when_its_table_does_not_exist(string $path): void
    {
        $this->migration($path)->up();

        $this->assertSame([], $this->snapshot(), 'An ALTER stub created a table. That is a create.');
    }

    /**
     * …and a TRUE no-op, not merely a harmless one, on a database created from the current stubs.
     *
     * The comparable includes column TYPES, which {@see snapshot()} deliberately does not: this is
     * the one test in the file whose whole subject is a type, and a name-and-index comparison would
     * pass against an ALTER that silently rewrote every column it touched. `->change()` is a full
     * column redefinition, so an unguarded ALTER shipped beside a correct create is a standing
     * reset of anything a host legitimately changed about those columns.
     */
    #[DataProvider('alterStubs')]
    public function test_an_alter_stub_changes_nothing_on_a_freshly_created_database(string $path): void
    {
        foreach (static::stubs() as [$create]) {
            $this->migration($create)->up();
        }

        $before = $this->typedSnapshot();
        $this->assertNotSame([], $before);

        $this->migration($path)->up();
        $this->assertSame($before, $this->typedSnapshot());

        // Twice, because a publish that lands this file at a host is not the last time `migrate`
        // will consider it — a re-published stem re-runs under a new stamp.
        $this->migration($path)->up();
        $this->assertSame($before, $this->typedSnapshot());
    }

    protected function migration(string $path): Migration
    {
        return require $path;
    }

    /**
     * Columns and index names of every table this suite knows about that currently exists — the
     * comparable the second pass must not move.
     *
     * @return array<string, array{columns: list<string>, indexes: list<string>}>
     */
    protected function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_column(Schema::getColumns($table), 'name');
            $indexes = array_column(Schema::getIndexes($table), 'name');

            sort($columns);
            sort($indexes);

            $snapshot[$table] = ['columns' => $columns, 'indexes' => $indexes];
        }

        return $snapshot;
    }

    /**
     * {@see snapshot()} plus each column's driver-reported type name — the comparable an ALTER test
     * needs, and the one the convergence tests deliberately do without (their subject is what a
     * second pass ADDS, and a type mismatch there is the guard's own job to throw about).
     *
     * @return array<string, array<string, string>>
     */
    protected function typedSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $types = [];

            foreach (Schema::getColumns($table) as $column) {
                $types[(string) $column['name']] = strtolower((string) ($column['type_name'] ?? '?'))
                    .($column['nullable'] ?? false ? ' null' : ' not null');
            }

            ksort($types);

            $snapshot[$table] = $types;
        }

        return $snapshot;
    }
}
