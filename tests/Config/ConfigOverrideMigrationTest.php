<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 05: the shipped override migration stub, run as the host runs it, on this suite's SQLite.
 * It creates two NEW tables, is convergent (a second pass into the same schema, as a host that runs both the central
 * and the tenant pass into one schema does, writes nothing and throws nothing), and `down` removes exactly its own.
 * {@see ConfigOverrideMigrationPostgresTest} runs the same assertions on Postgres in central AND a tenant schema.
 */
class ConfigOverrideMigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function migration(): object
    {
        return require __DIR__.'/../../database/migrations/shared/create_beam_config_overrides_table.php.stub';
    }

    /** @return list<string> */
    protected function columns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return $columns;
    }

    public function test_it_creates_the_revision_log_and_the_scope_table_and_converges_on_a_second_pass(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(
            ['created_at', 'created_by', 'encrypted_value', 'id', 'op', 'pointer', 'revision', 'section', 'value'],
            $this->columns(Beam::table('config_overrides')),
        );
        $this->assertSame(
            ['created_at', 'draft', 'head_version', 'id', 'published_version', 'section', 'updated_at'],
            $this->columns(Beam::table('config_scopes')),
        );

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable(Beam::table('config_overrides')));
        $this->assertFalse(Schema::hasTable(Beam::table('config_scopes')));
    }
}
