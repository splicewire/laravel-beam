<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideCounter;
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

    /** Boot a second application, hand it to `$read`, and put the test's application back. */
    private function inAnotherProcess(callable $read): mixed
    {
        $app = $this->createApplication();

        try {
            return $read($app);
        } finally {
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
        }
    }

    /** One Octane operation: a fresh clone of the worker's repository, as `CreateConfigurationSandbox` binds it. */
    private function operation(ConfigRepository $worker): ConfigRepository
    {
        $this->app->instance('config', $clone = clone $worker);

        return $clone;
    }

    /** Another long-lived process: its own overlay instance, sharing only the cache and the store. */
    private function anotherWarmProcess(): ConfigOverlay
    {
        return new ConfigOverlay($this->app, $this->app->make(ConfigOverrideCounter::class));
    }

    private function flakyCounterCache(): WriteFailingArrayStore
    {
        $store = new WriteFailingArrayStore;
        Cache::extend('cs05-flaky', fn () => new Repository($store));
        config()->set('cache.stores.cs05-flaky', ['driver' => 'cs05-flaky']);
        config()->set('beam.core.config_overrides.cache_store', 'cs05-flaky');

        return $store;
    }

    /** review-r1 HIGH-1: every fresh Octane clone gets the published layer, even after another clone recorded the token. */
    public function test_two_fresh_request_clones_after_a_publish_both_get_the_operator_tier(): void
    {
        app(ConfigOverlay::class)->sync();
        $worker = app('config');

        $this->operation($worker);
        $this->publishTitle('From the operator');
        $this->assertSame('From the operator', config('panels.title'), 'request 1, which published');

        foreach ([2, 3] as $request) {
            $this->operation($worker);
            app(ConfigOverlay::class)->sync();
            $this->assertSame('From the operator', config('panels.title'), "request {$request}: a fresh clone of the worker repository");
            $this->assertFalse(config('panels.enabled'), "request {$request}: the host value of an untouched key");
        }

        $this->assertSame('Panels', $worker->get('panels.title'), 'the worker repository itself was never written by a request');
    }

    /** review-r1 HIGH-1: restoration uses the package+host base, not a clone's inherited override. */
    public function test_an_override_applied_at_worker_boot_then_unset_reverts_to_the_host_value_in_a_fresh_clone(): void
    {
        $this->publishTitle('From the operator');
        app(ConfigOverlay::class)->sync();
        $worker = app('config');
        $this->assertSame('From the operator', $worker->get('panels.title'), 'the worker booted with the override');

        $this->operation($worker);
        app(ConfigOverrideStore::class)->saveDraft('panels', [['pointer' => '/title', 'op' => 'unset']]);
        app(ConfigOverrideStore::class)->publish('panels');

        $this->operation($worker);
        app(ConfigOverlay::class)->sync();
        $this->assertSame('Panels', config('panels.title'), 'a clone of the worker (which still holds the old override) reverts');
    }

    public function test_the_base_is_captured_before_the_first_apply_on_a_console_boot(): void
    {
        $this->publishTitle('From the operator');

        [$value, $base] = $this->inAnotherProcess(fn ($app): array => [
            $app['config']->get('panels.title'),
            $app->make(ConfigOverlay::class)->base('panels')['title'] ?? null,
        ]);

        $this->assertSame('From the operator', $value, 'the console boot applied the override');
        $this->assertSame('Panels', $base, 'its base was captured before that apply');
    }

    public function test_a_host_set_on_a_touched_root_stands_until_the_next_operation(): void
    {
        $this->publishTitle('From the operator');
        app(ConfigOverlay::class)->sync();
        $worker = app('config');

        $this->operation($worker);
        app(ConfigOverlay::class)->sync();
        config()->set('panels.title', 'Set by the request');
        app(ConfigOverlay::class)->sync();
        $this->assertSame('Set by the request', config('panels.title'), 'the same operation: its own write stands');

        $this->operation($worker);
        app(ConfigOverlay::class)->sync();
        $this->assertSame('From the operator', config('panels.title'), 'the next operation starts from the published state');
    }

    public function test_an_unchanged_token_applies_a_fresh_clone_without_reading_the_store(): void
    {
        $this->publishTitle('From the operator');
        app(ConfigOverlay::class)->sync();
        $worker = app('config');

        DB::connection('central')->flushQueryLog();
        DB::connection('central')->enableQueryLog();
        $this->operation($worker);
        app(ConfigOverlay::class)->sync();
        $queries = DB::connection('central')->getQueryLog();
        DB::connection('central')->disableQueryLog();

        $this->assertSame('From the operator', config('panels.title'));
        $this->assertSame([], $queries, 'the memoised layers serve the new clone');
    }

    /**
     * review-r1 HIGH-2 (a): a readable cache whose write fails is caught up within recheck_seconds. The bounded delay is
     * the accepted fault-mode guarantee (root ruling (A), row 64e24681); 0 makes it strict (the next test).
     */
    public function test_a_publish_whose_cache_write_fails_reaches_a_warm_process_within_the_recheck_bound(): void
    {
        config()->set('beam.core.config_overrides.recheck_seconds', 30);
        $cache = $this->flakyCounterCache();
        $this->publishTitle('One');
        $base = app('config');

        // The warm process: its own overlay and its own repository, at "One".
        $warmRepo = $this->operation($base);
        $warm = $this->anotherWarmProcess();
        $warm->sync();
        $this->assertSame('One', $warmRepo->get('panels.title'));

        // Another process publishes "Two"; its cache write fails, so the cache still announces the old token.
        $cache->failWrites = true;
        $this->operation($base);
        $this->publishTitle('Two');
        $this->assertSame('Two', config('panels.title'), 'the publishing process applied it at once');

        $this->app->instance('config', $warmRepo);
        $warm->sync();
        $this->assertSame('One', config('panels.title'), 'inside the bound the warm process trusts the stale cache');

        $this->travel(31)->seconds();
        $warm->sync();
        $this->assertSame('Two', config('panels.title'), 'past the bound it re-derives the token from the store');

        $cache->failWrites = false;
        $this->travel(31)->seconds();
        $warm->sync();
        $this->assertSame('Two', config('panels.title'), 'the cache recovering never takes it back to the old value');
        $this->assertSame(app(ConfigOverrideStore::class)->publishedToken(), Cache::store('cs05-flaky')->get(ConfigOverrideCounter::KEY), 'and the recheck repaired the cache');
    }

    /** Root ruling (A), row 64e24681: `recheck_seconds = 0` is strict, so a failed write still reaches the next sync. */
    public function test_strict_recheck_reaches_a_warm_process_on_its_next_sync_when_the_cache_write_fails(): void
    {
        config()->set('beam.core.config_overrides.recheck_seconds', 0);
        $cache = $this->flakyCounterCache();
        $this->publishTitle('One');
        $base = app('config');

        $warmRepo = $this->operation($base);
        $warm = $this->anotherWarmProcess();
        $warm->sync();
        $this->assertSame('One', $warmRepo->get('panels.title'));

        $cache->failWrites = true;
        $this->operation($base);
        $this->publishTitle('Two');

        // No time travel: the very next sync of the warm process sees the publish.
        $this->app->instance('config', $warmRepo);
        $warm->sync();
        $this->assertSame('Two', config('panels.title'), 'strict recheck re-derives the token from the store on every sync');
    }

    /** review-r1 HIGH-2 (b): a flushed counter followed by a publish never reuses a token a warm process applied. */
    public function test_a_flushed_counter_then_a_publish_reaches_a_warm_process(): void
    {
        $this->publishTitle('One');
        $base = app('config');
        $warmRepo = $this->operation($base);
        $warm = $this->anotherWarmProcess();
        $warm->sync();
        $this->assertSame('One', $warmRepo->get('panels.title'));

        Cache::store('array')->flush();
        $this->operation($base);
        $this->publishTitle('Two');

        $this->app->instance('config', $warmRepo);
        $warm->sync();
        $this->assertSame('Two', config('panels.title'), 'the token is a fingerprint of the published pins, never a reused count');
    }

    /** Lead note 18:33Z: the publishing process applies its own publish at once, whatever the cache does. */
    public function test_the_publishing_process_applies_its_publish_at_once_when_the_cache_write_fails(): void
    {
        $cache = $this->flakyCounterCache();
        $this->publishTitle('One');
        $cache->failWrites = true;

        $this->publishTitle('Two');
        $this->assertSame('Two', config('panels.title'), 'at once, with no recheck wait');

        app(ConfigOverlay::class)->sync();
        $this->assertSame('Two', config('panels.title'), 'and the stale cache does not take it back');
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
        // Another process published: the cached token moved, so this process must re-read, and the store is gone.
        Cache::store('array')->forever(ConfigOverrideCounter::KEY, 'a-token-this-process-never-applied');
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
            $this->assertSame('Panels', $this->inAnotherProcess(fn ($app) => $app->make(ConfigOverlay::class)->base('panels')['title'] ?? null), 'on the cached-config boot the base is captured from the cached file, before the apply');
        } finally {
            $this->artisan('config:clear')->assertSuccessful();
            // `config:cache` boots testbench's own Application, which calls Env::disablePutenv() and re-enables it only
            // when that inner application terminates, which it never does here. Put the process-wide flag back.
            Env::enablePutenv();
        }
    }
}

/** A cache store that reads fine but whose writes throw while `$failWrites` is on: a transient outage during a publish. */
final class WriteFailingArrayStore extends ArrayStore
{
    public bool $failWrites = false;

    public function put($key, $value, $seconds)
    {
        if ($this->failWrites) {
            throw new RuntimeException('cache write refused');
        }

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        if ($this->failWrites) {
            throw new RuntimeException('cache write refused');
        }

        return parent::increment($key, $value);
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
