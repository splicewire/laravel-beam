<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\TestCase;

/** PostgreSQL regression coverage for the read-boundary probe's fail-closed error path. */
class ResourceReadGuardPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        if (env('BEAM_TEST_PGSQL_DATABASE') === null) {
            $this->markTestSkipped(
                'Set BEAM_TEST_PGSQL_DATABASE to a throwaway database to run the PostgreSQL transaction probe.',
            );
        }

        parent::setUp();
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

    public function test_a_failed_scope_probe_does_not_abort_the_callers_transaction(): void
    {
        Exceptions::fake();
        $resource = new ParticleResource(
            key: 'missing-relation',
            backing: MissingRelationReadModel::class,
            scope: function ($query) {
                DB::select('select * from resource_read_guard_missing_relation');

                return $query;
            },
            readOnly: true,
            label: 'Missing relation',
        );

        DB::beginTransaction();

        try {
            $this->assertTrue(ResourceReadGuard::forApp()->inspectRead($resource, Request::create('/'))->denied());
            $this->assertSame(1, DB::selectOne('select 1 as value')->value);
            Exceptions::assertReported(fn (QueryException $e): bool => $e->getCode() === '42P01');
        } finally {
            DB::rollBack();
        }
    }
}

class MissingRelationReadModel extends Model
{
    protected $table = 'resource_read_guard_missing_relation';
}
