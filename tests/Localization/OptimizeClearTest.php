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
 * `optimize` runs here with `--except=config,events,routes,views`: the framework's own four tasks boot a fresh testbench
 * Application each (config:cache, route:cache…), which calls Env::disablePutenv() and never re-enables it, and loads
 * whatever providers the skeleton's discovery manifest lists (after the full suite, beam's own, whose Frame routes need
 * an API root the bare skeleton lacks). Every package's registered optimize task still runs, through optimize itself.
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

it('builds the fingerprint artifact on optimize and removes it on optimize:clear', function (): void {
    LangFingerprint::clearArtifact();
    expect(is_file(LangFingerprint::artifactPath()))->toBeFalse();

    expect(ServiceProvider::$optimizeCommands['beam-lang-fingerprint'] ?? null)->toBe('beam:lang:fingerprint')
        ->and(Artisan::call('optimize', ['--except' => 'config,events,routes,views']))->toBe(0)
        ->and(is_file(LangFingerprint::artifactPath()))->toBeTrue()
        ->and((require LangFingerprint::artifactPath())['translations'] ?? null)->toBe(LangFingerprint::computeTranslations());

    expect(Artisan::call('optimize:clear'))->toBe(0)
        ->and(is_file(LangFingerprint::artifactPath()))->toBeFalse();
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
