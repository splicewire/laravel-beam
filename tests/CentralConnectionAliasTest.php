<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Tests\Fixtures\AliasProbeProvider;

/**
 * The `central` alias, homed in CORE (beam-facade ticket 96; originally ticket 79, in beam-accounts).
 *
 * The move's whole argument is in this file's subject: the model under test is
 * {@see CentralActivityLog}, which is CORE's own pin, and no registration in beam-accounts could
 * ever reach it — beam-core requires nothing, so a host installing core alone gets the pin and not
 * the alias. Five Herd hosts were in exactly that shape when this was measured (2026-08-26):
 * beam-pilot-gcp-cloud-run, calcucrypt, entreport, stephenrushing, thingsontv, each throwing
 * `InvalidArgumentException: Database connection [central] not configured.` on
 * `CentralActivityLog::query()`.
 *
 * This suite boots ONLY beam-core's provider — deliberately not the package `TestCase`, and
 * deliberately without beam-accounts — because "core covers its own pin unaided" is the claim, and
 * a harness that registers anything above core cannot make it.
 *
 * Deliberately a FILE-backed sqlite rather than `:memory:`: two connections both configured as
 * `:memory:` are two SEPARATE databases, so an in-memory suite can prove the connection RESOLVES but
 * never that the alias reaches the same data.
 * {@see self::test_the_pinned_model_reads_the_default_database()} is the one that matters.
 *
 * The guard branches are driven through {@see AliasProbeProvider} rather than through
 * `defineEnvironment` — see that class for why a Testbench `database.*` override cannot reach the
 * register-time read.
 */
class CentralConnectionAliasTest extends Orchestra
{
    protected string $databasePath;

    protected function getPackageProviders($app): array
    {
        return [
            // popcorn's shared RegistryIndex singleton — see TestCase::getPackageProviders().
            PopcornServiceProvider::class,
            // CentralActivityLog extends spatie's Activity.
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            // beam-core, alone. NOT beam-accounts: the claim is that core covers its own pin.
            BeamServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->databasePath ??= tempnam(sys_get_temp_dir(), 'beam-core-central-').'.sqlite';
        touch($this->databasePath);

        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
            ]);
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    /**
     * Re-run the alias against the config state this test has set up, as the register phase would
     * see it at a real host, and drop any connection already opened under the old block.
     */
    protected function aliasAgainstCurrentConfig(): void
    {
        DB::purge('central');

        (new AliasProbeProvider($this->app))->probeCentralConnectionAlias();
    }

    public function test_the_provider_registers_central_with_no_host_action(): void
    {
        // Nothing in defineEnvironment() defined `central`; beam-CORE's provider did, at register time.
        $this->assertNotNull(config('database.connections.central'));
        $this->assertSame('sqlite', config('database.connections.central.driver'));
    }

    public function test_the_pinned_model_resolves_its_connection(): void
    {
        $this->assertSame('central', (new CentralActivityLog)->getConnection()->getName());
    }

    public function test_the_alias_is_a_copy_of_the_default_connection(): void
    {
        config(['database.connections.central' => null]);

        $this->aliasAgainstCurrentConfig();

        $this->assertSame(
            config('database.connections.testing'),
            config('database.connections.central'),
        );
    }

    public function test_a_host_defined_central_block_wins(): void
    {
        config(['database.connections.central' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'host_',
        ]]);

        $this->aliasAgainstCurrentConfig();

        $this->assertSame('host_', config('database.connections.central.prefix'));
        $this->assertSame(':memory:', config('database.connections.central.database'));
    }

    public function test_no_alias_is_fabricated_when_central_is_itself_the_default(): void
    {
        config([
            'database.default' => 'central',
            'database.connections.central' => null,
        ]);

        $this->aliasAgainstCurrentConfig();

        // Nothing to copy FROM — the missing block IS the default block, a real misconfiguration
        // whose own error message is more useful than a self-referential copy.
        $this->assertNull(config('database.connections.central'));
    }

    /**
     * The assertion the estate's five throwing hosts would have failed: a row written over the
     * DEFAULT connection, read back through the pinned model. Under two `:memory:` blocks this
     * returns 0 rather than throwing, which is why the database here is file-backed.
     */
    public function test_the_pinned_model_reads_the_default_database(): void
    {
        config(['database.connections.central' => null]);

        $this->aliasAgainstCurrentConfig();

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        DB::connection(config('database.default'))->table('activity_log')->insert([
            'log_name' => 'tokens',
            'description' => 'created',
        ]);

        $this->assertSame('tokens', CentralActivityLog::query()->sole()->log_name);
    }
}
