<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 46: `php artisan optimize:clear` runs on every beam host.
 * - Laravel's optimize:clear calls each registered clear task as a command NAME. laravel-beam registered
 *   `beam:lang:fingerprint --clear`, which is not a name, so optimize:clear failed on every host.
 * - The clear task is now `beam:lang:fingerprint-clear`; `beam:lang:fingerprint --clear` still works when called directly.
 *
 * `optimize` runs here with `--except` = {@see exceptAllButBeam()}: only laravel-beam's own optimize tasks run, through
 * optimize itself. Excluded: the framework's four (config:cache, route:cache… each boot a fresh testbench Application,
 * which calls Env::disablePutenv() and never re-enables it, and loads whatever providers the skeleton's discovery
 * manifest lists) and every OTHER package's task (root 7c84aa0c: spatie/laravel-data's `data:cache-structures` writes
 * to the configured cache store). beam's own tasks are pinned to {@see BEAM_OPTIMIZE_TASKS}, so a new one fails loudly.
 */

afterEach(function (): void {
    // Whatever the outcome: no cached framework file, no frame manifest, no fingerprint artifact is left for a later
    // test, and the process-wide putenv flag is back on (as ConfigOverrideBootTest does after config:cache).
    foreach (['config:clear', 'route:clear', 'event:clear', 'view:clear', 'splicewire:beam:frame:clear'] as $command) {
        if (array_key_exists($command, Artisan::all())) {
            Artisan::call($command);
        }
    }
    LangFingerprint::clearArtifact();
    Env::enablePutenv();
});

it('runs optimize:clear with laravel-beam loaded', function (): void {
    expect(Artisan::call('optimize:clear'))->toBe(0)
        ->and(ServiceProvider::$optimizeClearCommands['beam-lang-fingerprint'] ?? null)->toBe('beam:lang:fingerprint-clear');
});

/**
 * `optimize --except` for everything that is not laravel-beam's own: the framework's four (each boots an inner testbench
 * app) and every OTHER package's registered task. Root 7c84aa0c: spatie/laravel-data registers `data:cache-structures`,
 * which writes to the configured cache store, so under a database cache store with no `cache` table (config-sections'
 * run) `optimize` failed on a task that is not this package's. beam's own tasks (the fingerprint and the frame
 * resource manifest) still run through `optimize` itself.
 */
function exceptAllButBeam(): string
{
    $others = array_keys(array_filter(ServiceProvider::$optimizeCommands, fn (string $command): bool => ! isBeamCommand($command)));

    return implode(',', ['config', 'events', 'routes', 'views', ...$others]);
}

/** laravel-beam's own artisan commands: `beam:…` and `splicewire:beam:…`. */
function isBeamCommand(string $command): bool
{
    return str_starts_with($command, 'beam:') || str_starts_with($command, 'splicewire:beam:');
}

/**
 * The optimize tasks laravel-beam registers, each known to write files, not the cache store. A new beam optimize task
 * fails the test below until it is added here, after confirming it is safe under any cache store.
 */
const BEAM_OPTIMIZE_TASKS = [
    'beam-frame-resources' => 'splicewire:beam:frame:cache',
    'beam-lang-fingerprint' => 'beam:lang:fingerprint',
];

it('registers exactly the known laravel-beam optimize tasks, so a new one fails loudly', function (): void {
    $beam = array_filter(ServiceProvider::$optimizeCommands, fn (string $command): bool => isBeamCommand($command));
    ksort($beam);

    expect($beam)->toBe(BEAM_OPTIMIZE_TASKS);
});

it('builds the fingerprint artifact on optimize and removes it on optimize:clear', function (): void {
    LangFingerprint::clearArtifact();
    expect(is_file(LangFingerprint::artifactPath()))->toBeFalse();

    expect(ServiceProvider::$optimizeCommands['beam-lang-fingerprint'] ?? null)->toBe('beam:lang:fingerprint')
        ->and(Artisan::call('optimize', ['--except' => exceptAllButBeam()]))->toBe(0)
        ->and(is_file(LangFingerprint::artifactPath()))->toBeTrue()
        ->and((require LangFingerprint::artifactPath())['translations'] ?? null)->toBe(LangFingerprint::computeTranslations());

    expect(Artisan::call('optimize:clear'))->toBe(0)
        ->and(is_file(LangFingerprint::artifactPath()))->toBeFalse();
});

it('builds the fingerprint artifact whatever the cache store, running no optimize task of another package', function (): void {
    // config-sections' run (root 7c84aa0c): a database cache store on the in-memory testing connection, with no `cache`
    // table. Any optimize task that writes to the cache would fail here; beam's own write files, not the cache.
    // CACHE_STORE=database reaches both the default store and laravel-data's structure cache (its config reads the env).
    config(['cache.default' => 'database', 'data.structure_caching.cache.store' => 'database']);
    app('cache')->forgetDriver('database');
    app()->forgetInstance(Spatie\LaravelData\Support\Caching\DataStructureCache::class);
    LangFingerprint::clearArtifact();

    // optimize first, so a regression shows as the real failure (the cache write), not as the list check below.
    expect(Artisan::call('optimize', ['--except' => exceptAllButBeam()]))->toBe(0)
        ->and(is_file(LangFingerprint::artifactPath()))->toBeTrue()
        ->and(exceptAllButBeam())->toContain('laravel-data');
});

it('keeps beam:lang:fingerprint --clear working for direct callers, beside the flag-free clear command', function (): void {
    foreach ([['beam:lang:fingerprint', ['--clear' => true]], ['beam:lang:fingerprint-clear', []]] as [$command, $arguments]) {
        Artisan::call('beam:lang:fingerprint');
        expect(is_file(LangFingerprint::artifactPath()))->toBeTrue();

        expect(Artisan::call($command, $arguments))->toBe(0)
            ->and(is_file(LangFingerprint::artifactPath()))->toBeFalse();
    }
});

it('names a defined command, with no flags, in every optimize and optimize:clear task it registers', function (): void {
    $defined = Artisan::all();
    // Each map on its own: they share keys (one per provider), so a spread would let a clear task hide its optimize task.
    $tasks = ServiceProvider::$optimizeCommands + [];
    $clears = ServiceProvider::$optimizeClearCommands + [];
    expect($tasks)->toHaveKey('beam-lang-fingerprint')->and($clears)->toHaveKey('beam-lang-fingerprint');
    foreach (['optimize' => $tasks, 'optimize:clear' => $clears] as $phase => $map) {
        foreach ($map as $key => $command) {
            expect($command)->not->toContain(' ', "{$phase} task {$key}")
                ->and(array_key_exists($command, $defined))->toBeTrue("{$phase} task {$key} names no defined command: {$command}");
        }
    }
});
