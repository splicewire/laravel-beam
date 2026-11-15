<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Tests\Fixtures\Config\PanelsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections ticket 05: a console command sees a published override at boot; an unreadable counter cache is skipped
 * and the published layer read uncached (root ruling df42bf60); an unreachable store falls back to the package and host
 * tiers; neither ever fails boot; `config:cache` caches the package and host tiers
 * only and boots unchanged. The store lives in a FILE SQLite database here, so a second application booted in the
 * same test reads what the first one published, as a separate process would.
 */
class ConfigOverrideBootTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private static string $database;

    /** The temp file this test made, removed in tearDown even after a test points the store elsewhere. */
    private static string $file;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $connection = ['driver' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.central', $connection);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('panels', array_merge(require self::FIXTURES.'/package/panels.php', require self::FIXTURES.'/host/panels.php'));
    }

    protected function setUp(): void
    {
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-boot-');
        @unlink($stem);
        self::$database = self::$file = $stem.'.sqlite';
        touch(self::$file);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(PanelsSection::class, owner: 'acme/panels', packageFile: realpath(self::FIXTURES.'/package/panels.php'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file(self::$file)) {
            unlink(self::$file);
        }
    }

    private function publishTitle(string $title): void
    {
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'set', 'value' => $title]]);
        app(ConfigOverrideStore::class)->publish('panels');
    }

    /** Boot a second application (the next console command), read one key, and put the test's application back. */
    private function bootAnotherProcess(string $key): mixed
    {
        $app = $this->createApplication();
        $value = $app['config']->get($key);
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        return $value;
    }

    public function test_a_console_command_sees_a_published_override_at_boot(): void
    {
        $this->publishTitle('From the operator');

        $this->assertSame('From the operator', $this->bootAnotherProcess('panels.title'));
        $this->assertFalse($this->bootAnotherProcess('panels.enabled'), 'every other key keeps its host value');
    }

    /**
     * Root ruling df42bf60 (2026-11-15, amending PRD:224): a counter CACHE that cannot be read is skipped, not the tier.
     * The published layer is read uncached, so the operator tier keeps applying, and one warning says so.
     */
    public function test_an_unreadable_counter_cache_is_skipped_and_the_operator_tier_still_applies_uncached(): void
    {
        $this->publishTitle('From the operator');
        app(ConfigOverlay::class)->sync();
        $this->assertSame('From the operator', config('panels.title'));

        Log::spy();
        Cache::extend('unreachable', fn () => new Repository(new UnreachableCacheStore));
        config()->set('cache.stores.unreachable', ['driver' => 'unreachable']);
        config()->set('beam.core.config_overrides.cache_store', 'unreachable');

        app(ConfigOverlay::class)->sync();

        $this->assertSame('From the operator', config('panels.title'), 'the operator tier wins; nothing threw');
        $this->assertFalse(config('panels.enabled'), 'a key with no override keeps its host value');

        // A publish while the cache is down cannot move the counter (the store logs that), yet the next sync sees it:
        // the read is uncached.
        $this->publishTitle('Published while the cache is down');
        app(ConfigOverlay::class)->sync();
        $this->assertSame('Published while the cache is down', config('panels.title'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'reading the published overrides uncached'))
            ->once();
    }

    public function test_an_unreachable_store_falls_back_and_never_fails_boot(): void
    {
        $this->publishTitle('From the operator');
        config()->set('database.connections.central', ['driver' => 'sqlite', 'database' => '/nonexistent/beam-config.sqlite', 'prefix' => '']);
        DB::purge('central');
        Cache::store('array')->increment('beam:config-overrides:published');
        Log::spy();

        app(ConfigOverlay::class)->sync();
        $this->assertSame('Panels', config('panels.title'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'the override store could not be read; config falls back to the package and host tiers'))
            ->once();

        // The next process boots on an unreachable store: it must boot, on the package and host tiers.
        $this->app['config']->set('database.connections.testing.database', '/nonexistent/beam-config.sqlite');
        self::$database = '/nonexistent/beam-config.sqlite';
        $this->assertSame('Panels', $this->bootAnotherProcess('panels.title'));
    }

    public function test_config_cache_holds_the_package_and_host_tiers_only_and_boots_unchanged(): void
    {
        $this->publishTitle('From the operator');
        $argv = $_SERVER['argv'] ?? null;

        try {
            // While `config:cache` builds its fresh application, the booted hook must not apply the operator tier.
            $_SERVER['argv'] = ['artisan', 'config:cache'];
            $this->assertTrue(ConfigOverlay::cachesConfiguration());
            $this->assertSame('Panels', $this->bootAnotherProcess('panels.title'), 'a cache built now holds no override');
        } finally {
            $_SERVER['argv'] = $argv;
        }

        try {
            $this->artisan('config:cache')->assertSuccessful();
            $cached = $this->app->getCachedConfigPath();
            $this->assertFileExists($cached);
            $this->assertStringNotContainsString('From the operator', file_get_contents($cached), 'the cached file holds no override');
            $this->assertSame('From the operator', $this->bootAnotherProcess('panels.title'), 'with config cached, the next process still sees the override');
        } finally {
            $this->artisan('config:clear')->assertSuccessful();
            // `config:cache` boots testbench's own Application, which calls Env::disablePutenv() and re-enables it only
            // when that inner application terminates, which it never does here. Put the process-wide flag back.
            Env::enablePutenv();
        }
    }
}

/** A cache store whose every read throws, as an unreachable Redis does. */
final class UnreachableCacheStore implements Store
{
    public function get($key): mixed
    {
        throw new RuntimeException('cache unreachable');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('cache unreachable');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function touch($key, $seconds): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function flush(): bool
    {
        throw new RuntimeException('cache unreachable');
    }

    public function getPrefix(): string
    {
        return '';
    }
}
