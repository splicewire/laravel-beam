<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 16 (LANE-A-0255, ruled in 0410 and 0420): the operator store's schema probes. A table FOUND is
 * remembered per PDO, so a warm process stops asking the schema on every sync; a table NOT found is asked again on the
 * next read (no process-life negative), and a token that says nothing is published reads no layers. A remembered table
 * that is dropped under a live process gives the no-store result, never an error, and a re-created one is seen again.
 * The store lives in a FILE SQLite database shared by the default connection and `central`, as in ConfigOverrideBootTest.
 */
class ConfigOverrideStoreProbeTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private const STUBS = __DIR__.'/../../database/migrations/shared/';

    private static string $file;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $connection = ['driver' => 'sqlite', 'database' => self::$file, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.central', $connection);
        $app['config']->set('cache.default', 'array');
        // Strict: every sync re-derives the token from the store, so every sync is a store read.
        $app['config']->set('beam.core.config_overrides.recheck_seconds', 0);
        $app['config']->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
    }

    protected function setUp(): void
    {
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-probe-');
        @unlink($stem);
        touch(self::$file = $stem.'.sqlite');

        parent::setUp();

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        app(ConfigSectionRegistry::class)->section(PanelsSection::class, owner: 'acme/panels', packageFile: realpath(self::FIXTURES.'/package/panels.php'));
        $this->migrate('create_beam_versions_table');
        $this->freshProcess();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file(self::$file)) {
            unlink(self::$file);
        }
    }

    private function migrate(string $stub): void
    {
        (require self::STUBS.$stub.'.php.stub')->up();
    }

    /**
     * A new process: its own store and overlay, so nothing an earlier write probed is remembered, and the host's config
     * (a publish applies itself to the publishing process's repository at once).
     */
    private function freshProcess(): void
    {
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        config()->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
    }

    private function publishTitle(string $title): void
    {
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => $title]]);
        app(ConfigOverrideStore::class)->publish('panels');
    }

    /** How many schema probes `$run` sends on `central` (SQLite's hasTable reads sqlite_master). */
    private function probes(callable $run): int
    {
        $central = DB::connection('central');
        $central->flushQueryLog();
        $central->enableQueryLog();

        try {
            $run();
        } finally {
            $central->disableQueryLog();
        }

        return count(array_filter($central->getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'sqlite_master')));
    }

    public function test_a_present_store_is_probed_once_per_pdo_across_syncs(): void
    {
        $this->migrate('create_beam_config_overrides_table');
        $this->publishTitle('From the operator');
        $this->freshProcess();
        $overlay = app(ConfigOverlay::class);

        $this->assertSame(1, $this->probes(fn () => $overlay->sync()), 'sync 1: the token read probes once and the layers read reuses it');
        $this->assertSame('From the operator', config('panels.title'));
        $this->assertSame(0, $this->probes(fn () => $overlay->sync()), 'sync 2: the same PDO, no probe');

        DB::connection('central')->reconnect();
        $this->assertSame(1, $this->probes(fn () => $overlay->sync()), 'a new PDO is probed again');
        $this->assertSame('From the operator', config('panels.title'));
    }

    public function test_an_absent_store_is_probed_once_per_sync_and_a_later_migration_is_seen(): void
    {
        $overlay = app(ConfigOverlay::class);

        $this->assertSame(1, $this->probes(fn () => $overlay->sync()), 'sync 1: the token read probes; a none token reads no layers');
        $this->assertSame(1, $this->probes(fn () => $overlay->sync()), 'sync 2: an absent table is asked again, once');
        $this->assertSame('Panels', config('panels.title'));

        $this->migrate('create_beam_config_overrides_table');
        $this->publishTitle('From the operator');
        $overlay->sync();
        $this->assertSame('From the operator', config('panels.title'), 'the table the migration made is seen at the next sync');
    }

    public function test_the_locks_table_is_probed_once_per_pdo_when_present_and_on_every_read_when_absent(): void
    {
        $store = app(ConfigOverrideStore::class);

        $this->assertSame(2, $this->probes(function () use ($store): void {
            $this->assertSame([], $store->locks('panels'));
            $this->assertSame([], $store->locks('panels'));
        }), 'absent: no negative is remembered');

        $this->migrate('create_beam_config_locks_table');

        $this->assertSame(1, $this->probes(function () use ($store): void {
            $this->assertSame([], $store->locks('panels'));
            $this->assertSame([], $store->locks('panels'));
        }), 'present: probed once');
    }

    public function test_a_remembered_store_dropped_under_a_live_process_reads_as_no_store_and_a_recreated_one_is_seen(): void
    {
        $this->migrate('create_beam_config_overrides_table');
        $this->migrate('create_beam_config_locks_table');
        $this->publishTitle('From the operator');
        $this->freshProcess();
        $store = app(ConfigOverrideStore::class);
        $overlay = app(ConfigOverlay::class);
        $overlay->sync();
        $this->assertSame([], $store->locks('panels'));
        $this->assertSame('From the operator', config('panels.title'));
        $this->assertSame(0, $this->probes(fn () => $store->publishedToken()), 'the table is remembered');

        Schema::connection('central')->drop(Beam::table('config_scopes'));
        Schema::connection('central')->drop(Beam::table('config_locks'));
        Log::spy();

        $this->assertSame(1, $this->probes(fn () => $this->assertSame(ConfigOverrideStore::NO_PUBLISHED_STATE, $store->publishedToken())), 'the failed read forgets the table and probes once');
        $this->assertSame([], $store->publishedLayers());
        $this->assertSame([], $store->storedListRows());
        $this->assertSame([], $store->locks('panels'));
        $overlay->sync();
        $this->assertSame('Panels', config('panels.title'), 'the overlay reads no store, as before a migration');
        Log::shouldNotHaveReceived('warning');

        $this->migrate('create_beam_config_overrides_table');
        $this->migrate('create_beam_config_locks_table');
        $this->publishTitle('Again');
        $this->assertNotSame(ConfigOverrideStore::NO_PUBLISHED_STATE, $store->publishedToken(), 'the re-created table is seen');
        $overlay->sync();
        $this->assertSame('Again', config('panels.title'));
    }

    public function test_a_remembered_store_whose_read_fails_while_the_table_stands_rethrows(): void
    {
        $this->migrate('create_beam_config_overrides_table');
        $this->publishTitle('From the operator');
        $this->freshProcess();
        $store = app(ConfigOverrideStore::class);
        $this->assertNotSame([], $store->publishedLayers());

        // The layers read reaches the versions table too; config_scopes stands.
        Schema::connection('central')->drop((new Version)->getTable());

        $thrown = null;
        $probes = $this->probes(function () use ($store, &$thrown): void {
            try {
                $store->publishedLayers();
            } catch (QueryException $e) {
                $thrown = $e;
            }
        });

        $this->assertInstanceOf(QueryException::class, $thrown, 'the table is still there: the read error is not hidden');
        $this->assertSame(1, $probes, 'one re-probe');
    }

    public function test_forget_store_tables_makes_the_next_read_probe_again(): void
    {
        $this->migrate('create_beam_config_overrides_table');
        $store = app(ConfigOverrideStore::class);
        $store->publishedToken();
        $this->assertSame(0, $this->probes(fn () => $store->publishedToken()));

        $store->forgetStoreTables();

        $this->assertSame(1, $this->probes(fn () => $store->publishedToken()));
    }
}
