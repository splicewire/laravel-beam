<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;

/**
 * {@see BeamHookMorphWideningTest}, re-run against a real Postgres — the driver the defect was
 * measured on and the only one that can see it.
 *
 * ## Why a whole class instead of a `@dataProvider` over connections
 *
 * The connection is chosen during application boot (`defineEnvironment`), before any data provider
 * or test method runs. A per-test connection switch would leave the container holding a schema
 * builder for the other driver, which is how a "Postgres run" ends up quietly asserting against
 * sqlite. A subclass gets one app, one driver, and no way to be confused about which.
 *
 * ## It SKIPS rather than fails when no database is offered
 *
 * Deliberately, and the trade is stated rather than assumed: a suite that requires a live Postgres
 * to be green cannot run in CI here or on a laptop that has not provisioned one, and the pressure
 * that follows is to delete the class. A skip keeps the instrument in the tree — but a skipped test
 * is not a passing one, so a claim that "the Postgres lane is green" has to name the run that
 * actually executed it:
 *
 * ```
 * createdb beam_hook_widening_probe
 * BEAM_TEST_PGSQL_DATABASE=beam_hook_widening_probe \
 *   XDEBUG_MODE=off herd php vendor/bin/pest tests/Schema/BeamHookMorphWideningPostgresTest.php
 * dropdb beam_hook_widening_probe
 * ```
 *
 * Every connection field is read from the environment with no default host or credential baked in,
 * so this can never reach for a developer's live database by accident: with the one required
 * variable unset, it does not connect at all.
 */
class BeamHookMorphWideningPostgresTest extends BeamHookMorphWideningTest
{
    protected function setUp(): void
    {
        if (env('BEAM_TEST_PGSQL_DATABASE') === null) {
            $this->markTestSkipped(
                'Set BEAM_TEST_PGSQL_DATABASE (a throwaway database) to run the Postgres lane — '
                .'see this class\'s docblock for the full invocation.',
            );
        }

        parent::setUp();

        // A leftover from an interrupted run must not read as the legacy shape — each test builds
        // the table it is about.
        Schema::dropIfExists(Beam::table('hooks'));
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
}
