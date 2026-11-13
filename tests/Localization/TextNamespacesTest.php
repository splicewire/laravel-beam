<?php

use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 07 — the namespace allocator (ruling 01 §3): a package's namespace is its composer name less the vendor
 * and a leading `laravel-`, or its declared `extra.beam.lang-namespace`; the owner of a class is the longest PSR-4
 * prefix; a shortened name two packages share is refused for both; a used namespace is hinted even when its package
 * ships no lang file, so a host's `lang/vendor/<ns>/` override reaches it.
 */

function namespacesFixture(array $extra = []): TextNamespaces
{
    return new TextNamespaces([
        ['name' => 'splicewire/laravel-beam', 'path' => '/pkgs/beam', 'psr4' => ['Splicewire\\Beam\\' => 'src/']],
        ['name' => 'splicewire/laravel-beam-ux', 'path' => '/pkgs/beam-ux', 'psr4' => ['Splicewire\\Beam\\Ux\\' => 'src/']],
        ['name' => 'schemastud/laravel-data-schemas', 'path' => '/pkgs/ds', 'psr4' => [
            'Schemastud\\DataSchemas\\' => 'src/',
            'Rushing\\LaravelDataSchemasScribe\\Attributes\\' => 'src/Http/Attributes/',
        ]],
        ['name' => 'rushing/codegen', 'path' => '/pkgs/codegen', 'psr4' => ['Rushing\\Codegen\\' => 'src/']],
        ['name' => 'rushing/laravel-codegen', 'path' => '/pkgs/lcodegen', 'psr4' => ['Rushing\\LaravelCodegen\\' => 'src/']],
        ...$extra,
    ], ['App\\'], app('translator'));
}

it('shortens a composer name to its namespace', function (): void {
    expect(TextNamespaces::shorten('splicewire/laravel-beam-ux'))->toBe('beam-ux')
        ->and(TextNamespaces::shorten('schemastud/laravel-frame'))->toBe('frame')
        ->and(TextNamespaces::shorten('rushing/laravel-data-filters'))->toBe('data-filters')
        ->and(TextNamespaces::shorten('splicewire/tower'))->toBe('tower');
});

it('owns a class by the longest PSR-4 prefix, every root of a multi-root package included', function (): void {
    $ns = namespacesFixture();

    expect($ns->ownerOfClass('Splicewire\\Beam\\Ux\\Data\\RedirectData')->namespace)->toBe('beam-ux')
        ->and($ns->ownerOfClass('Splicewire\\Beam\\Data\\RecordData')->namespace)->toBe('beam')
        ->and($ns->ownerOfClass('Rushing\\LaravelDataSchemasScribe\\Attributes\\Title')->namespace)->toBe('data-schemas');
});

it('makes a host class the host\'s, whose derived keys carry no namespace', function (): void {
    $owner = namespacesFixture()->ownerOfClass('App\\Data\\CalculatorSpecData');

    expect($owner->kind)->toBe('host')
        ->and($owner->keyPrefix())->toBe('');
});

it('refuses a namespace two installed packages shorten to, throwing where the host is built', function (): void {
    $ns = namespacesFixture();

    expect($ns->contested())->toBe(['codegen' => ['rushing/codegen', 'rushing/laravel-codegen']])
        ->and(fn () => $ns->ownerOfClass('Rushing\\Codegen\\Generator'))->toThrow(LogicException::class, 'contested');
});

it('reports a contested namespace in production and renders the literal (no derived key)', function (): void {
    config(['beam.localization.throw_on_contested_namespace' => false]);

    $owner = namespacesFixture()->ownerOfClass('Rushing\\Codegen\\Generator');

    expect($owner->isRefused())->toBeTrue()
        ->and($owner->keyPrefix())->toBeNull();
});

it('reads the contest switch from the localization section, not the nav-seat policy (localization 06)', function (): void {
    // The 07 stopgap borrowed `beam.ux.ia.throw`; the nav-seat switch no longer speaks for localization.
    config(['beam.ux.ia.throw' => false, 'beam.localization.throw_on_contested_namespace' => null]);

    expect(fn () => namespacesFixture()->ownerOfClass('Rushing\\Codegen\\Generator'))
        ->toThrow(LogicException::class, 'contested');
});

it('takes a package out of the contest when it declares extra.beam.lang-namespace', function (): void {
    $ns = new TextNamespaces([
        ['name' => 'rushing/codegen', 'path' => '/pkgs/codegen', 'psr4' => ['Rushing\\Codegen\\' => 'src/'],
            'extra' => ['beam' => ['lang-namespace' => 'rushing-codegen']]],
        ['name' => 'rushing/laravel-codegen', 'path' => '/pkgs/lcodegen', 'psr4' => ['Rushing\\LaravelCodegen\\' => 'src/']],
    ], [], app('translator'));

    expect($ns->contested())->toBe([])
        ->and($ns->ownerOfClass('Rushing\\Codegen\\X')->namespace)->toBe('rushing-codegen')
        ->and($ns->ownerOfClass('Rushing\\LaravelCodegen\\X')->namespace)->toBe('codegen');
});

it('hints a used namespace whose package ships no lang file, so a host override reaches it', function (): void {
    $host = sys_get_temp_dir().'/beam-ns-'.getmypid().'-'.bin2hex(random_bytes(4));
    @mkdir("{$host}/lang/vendor/beam-ux/en", 0777, true);
    file_put_contents("{$host}/lang/vendor/beam-ux/en/resources.php", "<?php return ['beam-ux-redirect' => ['label' => 'Forwards']];");
    app()->useLangPath("{$host}/lang");
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    expect(__('beam-ux::resources.beam-ux-redirect.label'))->toBe('beam-ux::resources.beam-ux-redirect.label');

    namespacesFixture()->ownerOfClass('Splicewire\\Beam\\Ux\\Data\\RedirectData');

    expect(__('beam-ux::resources.beam-ux-redirect.label'))->toBe('Forwards');
});

it('refuses a namespace already hinted at another path instead of overwriting it', function (): void {
    app('translator')->addNamespace('beam-ux', '/somewhere/else/lang');

    expect(fn () => namespacesFixture()->ownerOfClass('Splicewire\\Beam\\Ux\\Data\\RedirectData'))
        ->toThrow(LogicException::class, 'already hinted');
});

it('builds from composer metadata, the root library being one more package under its own tests', function (): void {
    $ns = app(TextNamespaces::class);

    expect($ns->namespaceOf('splicewire/laravel-beam'))->toBe('beam')
        ->and($ns->ownerOfClass(Splicewire\Beam\BeamServiceProvider::class)->namespace)->toBe('beam')
        ->and($ns->namespaceOf('schemastud/laravel-frame'))->toBe('frame');
});

it('hints a package that ships a lang directory once the translator exists', function (): void {
    $pkg = sys_get_temp_dir().'/beam-ns-shipped-'.getmypid().'-'.bin2hex(random_bytes(4));
    @mkdir("{$pkg}/lang/en", 0777, true);
    file_put_contents("{$pkg}/lang/en/messages.php", "<?php return ['hello' => 'Hello from the package'];");

    (new TextNamespaces([['name' => 'acme/laravel-widgets', 'path' => $pkg, 'psr4' => ['Acme\\Widgets\\' => 'src/']]], [], app('translator')))
        ->hintShipped();

    expect(__('widgets::messages.hello'))->toBe('Hello from the package');
});

it('never refuses at boot: a namespace a package already hinted elsewhere is left alone', function (): void {
    $pkg = sys_get_temp_dir().'/beam-ns-third-'.getmypid().'-'.bin2hex(random_bytes(4));
    @mkdir("{$pkg}/lang", 0777, true);
    app('translator')->addNamespace('widgets', '/the/package/own/resources/lang');

    (new TextNamespaces([['name' => 'acme/laravel-widgets', 'path' => $pkg, 'psr4' => ['Acme\\Widgets\\' => 'src/']]], [], app('translator')))
        ->hintShipped();

    expect(app('translator')->getLoader()->namespaces()['widgets'])->toBe('/the/package/own/resources/lang');
});
