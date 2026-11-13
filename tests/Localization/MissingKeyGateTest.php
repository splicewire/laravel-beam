<?php

use Illuminate\Support\Facades\Lang;
use Splicewire\Beam\Localization\MissingTranslationKey;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 12 — a mistyped key cannot ship quietly. While unit tests run, a namespaced key with no line throws, but
 * only in a namespace a FAMILY package owns (coord-lead ruling (a)): a host's own suite never goes red on a key it does
 * not own. Installed by beam's provider, so every family package and host inherits it.
 */

function gateLang(array $files): void
{
    $root = sys_get_temp_dir().'/beam-gate-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    // An emit point hints a namespace when it resolves the owner; do the same.
    app(TextNamespaces::class)->ownerOfPackage('splicewire/laravel-beam');
}

it('throws for a mistyped key in a family namespace, naming the key', function (): void {
    gateLang(['vendor/beam/en/gate.php' => ['hello' => 'Hello']]);

    expect(__('beam::gate.hello'))->toBe('Hello')
        ->and(fn () => __('beam::gate.helo'))->toThrow(MissingTranslationKey::class, 'beam::gate.helo')
        ->and(fn () => trans_choice('beam::gate.apples', 2))->toThrow(MissingTranslationKey::class);
});

it('never throws for a third-party vendor namespace, an unnamespaced host key or a sentence key', function (): void {
    gateLang([]);
    app('translator')->addNamespace('spatie-thing', sys_get_temp_dir().'/beam-gate-none');

    expect(__('spatie-thing::messages.missing'))->toBe('spatie-thing::messages.missing')
        ->and(__('host.missing'))->toBe('host.missing')
        ->and(__('A sentence nobody translated'))->toBe('A sentence nobody translated')
        ->and(__('auth.failed'))->toBe('These credentials do not match our records.');
});

it('never throws from an existence probe', function (): void {
    gateLang([]);

    expect(Lang::has('beam::gate.missing'))->toBeFalse();
});

it('guards only namespaces a splicewire, schemastud or rushing package owns', function (): void {
    $namespaces = new TextNamespaces([
        ['name' => 'splicewire/laravel-beam-ux', 'path' => '/p/ux', 'psr4' => []],
        ['name' => 'schemastud/laravel-frame', 'path' => '/p/frame', 'psr4' => []],
        ['name' => 'spatie/laravel-data', 'path' => '/p/data', 'psr4' => []],
    ]);

    expect(MissingTranslationKey::guards('beam-ux::nav.x', $namespaces))->toBeTrue()
        ->and(MissingTranslationKey::guards('frame::widgets.y', $namespaces))->toBeTrue()
        ->and(MissingTranslationKey::guards('data::messages.z', $namespaces))->toBeFalse()
        ->and(MissingTranslationKey::guards('unknown::messages.z', $namespaces))->toBeFalse()
        ->and(MissingTranslationKey::guards('auth.failed', $namespaces))->toBeFalse()
        ->and($namespaces->familyNamespaces())->toBe(['beam-ux', 'frame']);
});

it('is off outside tests, and a host can switch it off in tests', function (): void {
    gateLang([]);

    config(['beam.localization.throw_on_missing_key' => false]);
    expect(__('beam::gate.missing'))->toBe('beam::gate.missing');

    config(['beam.localization.throw_on_missing_key' => null]);
    app()->detectEnvironment(fn () => 'production');
    expect(app()->runningUnitTests())->toBeFalse()
        ->and(__('beam::gate.missing'))->toBe('beam::gate.missing');
});
