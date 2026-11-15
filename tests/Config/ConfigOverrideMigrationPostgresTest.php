<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;

/**
 * {@see ConfigOverrideMigrationTest} on a real Postgres, in the CENTRAL schema and in a TENANT schema, the two passes
 * beam-tenancy's `registerSharedMigrationsPath()` runs the shared stub in (ADR-0245 §9).
 *
 * It SKIPS rather than fails when no database is offered, the trade {@see \Splicewire\Beam\Tests\Schema\BeamHookMorphWideningPostgresTest}
 * states: a skip is not a pass, so a claim that the Postgres lane is green names the run that executed it.
 *
 * ```
 * createdb beam_config_overrides_probe
 * BEAM_TEST_PGSQL_DATABASE=beam_config_overrides_probe XDEBUG_MODE=off herd php vendor/bin/pest tests/Config/ConfigOverrideMigrationPostgresTest.php
 * dropdb beam_config_overrides_probe
 * ```
 */
class ConfigOverrideMigrationPostgresTest extends ConfigOverrideMigrationTest
{
    private const TENANT = 'tenant_config_overrides_probe';

    protected function setUp(): void
    {
        if (env('BEAM_TEST_PGSQL_DATABASE') === null) {
            $this->markTestSkipped('Set BEAM_TEST_PGSQL_DATABASE (a throwaway database) to run the Postgres lane; see the docblock.');
        }

        parent::setUp();

        DB::statement('DROP SCHEMA IF EXISTS '.self::TENANT.' CASCADE');
        Schema::dropIfExists(Beam::table('config_overrides'));
        Schema::dropIfExists(Beam::table('config_scopes'));
    }

    protected function tearDown(): void
    {
        if (! isset($this->app)) {
            return; // skipped in setUp, before the application was created
        }

        DB::statement('SET search_path TO public');
        DB::statement('DROP SCHEMA IF EXISTS '.self::TENANT.' CASCADE');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('BEAM_TEST_PGSQL_HOST', '127.0.0.1'),
            'port' => env('BEAM_TEST_PGSQL_PORT', '5432'),
            'database' => env('BEAM_TEST_PGSQL_DATABASE'),
            'username' => env('BEAM_TEST_PGSQL_USERNAME', 'postgres'),
            'password' => env('BEAM_TEST_PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    public function test_it_runs_in_the_central_schema_and_in_a_tenant_schema(): void
    {
        $this->migration()->up();
        $this->assertTrue(Schema::hasTable(Beam::table('config_overrides')));

        DB::statement('CREATE SCHEMA '.self::TENANT);
        DB::statement('SET search_path TO '.self::TENANT);
        $this->migration()->up();
        $this->migration()->up();

        $tenantTables = collect(DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [self::TENANT]))
            ->pluck('table_name')->all();
        $this->assertSame([Beam::table('config_overrides'), Beam::table('config_scopes')], $tenantTables);

        $this->migration()->down();
        DB::statement('SET search_path TO public');
        $this->assertTrue(Schema::hasTable(Beam::table('config_scopes')), 'the tenant down never touches central');
        $this->migration()->down();
    }
}
