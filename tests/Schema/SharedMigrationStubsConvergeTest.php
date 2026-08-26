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
     * @return array<string, array{string}>
     */
    public static function stubs(): array
    {
        $directory = dirname(__DIR__, 2).'/database/migrations/shared';

        $cases = [];

        foreach (glob($directory.'/*.php.stub') ?: [] as $path) {
            $cases[basename($path, '.php.stub')] = [$path];
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
}
