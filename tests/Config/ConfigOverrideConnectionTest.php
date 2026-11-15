<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 05: the operator tier lives on `central`, and the request's DEFAULT connection may be another
 * database (a tenant's, or a host whose default is not central) with its own `versions` table. Every read of the
 * published layer must go to central. Here the two connections are two separate SQLite files; the other tests share one
 * file, which is how a version read on the default connection went unseen until beam-ux's request-path test (05).
 */
class ConfigOverrideConnectionTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    /** @var array{central: string, default: string} */
    private static array $files;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        foreach (['central' => 'central', 'testing' => 'default'] as $connection => $file) {
            $app['config']->set("database.connections.{$connection}", ['driver' => 'sqlite', 'database' => self::$files[$file], 'prefix' => '', 'foreign_key_constraints' => false]);
        }
        $app['config']->set('cache.default', 'array');
        $app['config']->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
    }

    protected function setUp(): void
    {
        foreach (['central', 'default'] as $name) {
            $stem = tempnam(sys_get_temp_dir(), "beam-config-{$name}-");
            @unlink($stem);
            self::$files[$name] = $stem.'.sqlite';
            touch(self::$files[$name]);
        }

        parent::setUp();

        // The shared stubs on central, as the host's central migrate pass runs them, and a versions table of the
        // default connection's own (a tenant's, holding other records' versions).
        foreach (['central', 'testing'] as $connection) {
            $default = DB::getDefaultConnection();
            DB::setDefaultConnection($connection);
            try {
                (require __DIR__.'/../../database/migrations/shared/create_beam_versions_table.php.stub')->up();
                if ($connection === 'central') {
                    (require __DIR__.'/../../database/migrations/shared/create_beam_config_overrides_table.php.stub')->up();
                }
            } finally {
                DB::setDefaultConnection($default);
            }
        }

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(PanelsSection::class, owner: 'acme/panels', packageFile: realpath(self::FIXTURES.'/package/panels.php'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (self::$files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_the_published_layer_is_read_from_central_when_the_default_connection_is_another_database(): void
    {
        $store = app(ConfigOverrideStore::class);
        $store->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => 'From the operator']]);
        $store->publish('panels');

        $layer = $store->publishedLayers()['panels'] ?? [];

        $this->assertArrayHasKey('/title', $layer, 'the published version is read on central, not the default connection');
        $this->assertSame(0, DB::connection('testing')->table(config('versioning.table', 'versions'))->count(), 'nothing was written to the default connection');

        app(ConfigOverlay::class)->sync();
        $this->assertSame('From the operator', config('panels.title'));
    }
}
