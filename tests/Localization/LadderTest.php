<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceOverride;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 07 — the ladder, asserted through the framework's own lookup surface (`__()`, `trans_choice()`, the
 * validator, a request that carries a realm) against a fixture grid of locales and realms. This is the one place the
 * lookup order is asserted (SPEC §Testing Decisions, seam 2): the locale chain is the OUTER loop, the realm chain the
 * inner one (ruling 05 §2).
 */

/**
 * Write a host lang tree (and optionally one package's) and rebuild the translator over it.
 *
 * @param  array<string, array<string, mixed>>  $host  relative path (`es/resources.php`, `en/user/resources.php`) => lines
 * @param  array<string, array<string, mixed>>  $package  the `probe` package's own lang tree
 */
function ladderLang(array $host, array $package = []): void
{
    $root = sys_get_temp_dir().'/beam-ladder-'.getmypid().'-'.bin2hex(random_bytes(4));

    foreach (['lang' => $host, 'probe-lang' => $package] as $dir => $files) {
        foreach ($files as $path => $lines) {
            $file = "{$root}/{$dir}/{$path}";
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, '<?php return '.var_export($lines, true).';');
        }
    }

    app()->useLangPath("{$root}/lang");
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    app()->forgetInstance('validator');
    app('translator')->addNamespace('probe', "{$root}/probe-lang");
    app('translator')->setFallback('en');
}

beforeEach(function (): void {
    // `studio` composes through `tenant` and then `operator`: its chain is studio → tenant → operator → base.
    app(RealmRegistry::class)->register(new RealmDefinition('studio', '/studio', null, false, ['operator', 'tenant']));
});

it('binds beam\'s ladder as the application translator', function (): void {
    expect(app('translator'))->toBeInstanceOf(BeamTranslator::class);
});

it('walks a realm stack from most to least specific before the base', function (): void {
    ladderLang([
        'en/messages.php' => ['a' => 'base a', 'b' => 'base b', 'c' => 'base c'],
        'en/operator/messages.php' => ['a' => 'operator a', 'b' => 'operator b'],
        'en/tenant/messages.php' => ['a' => 'tenant a'],
    ]);

    $t = app('translator');
    expect($t->getIn('studio', 'messages.a'))->toBe('tenant a')
        ->and($t->getIn('studio', 'messages.b'))->toBe('operator b')
        ->and($t->getIn('studio', 'messages.c'))->toBe('base c');
});

it('tries a regional locale, then its parent language, then the fallback', function (): void {
    ladderLang([
        'en/messages.php' => ['a' => 'en a', 'b' => 'en b', 'c' => 'en c'],
        'es/messages.php' => ['a' => 'es a', 'b' => 'es b'],
        'es-MX/messages.php' => ['a' => 'es-MX a'],
    ]);
    app()->setLocale('es-MX');

    expect(__('messages.a'))->toBe('es-MX a')
        ->and(__('messages.b'))->toBe('es b')
        ->and(__('messages.c'))->toBe('en c');
});

it('treats the locale chain as the outer loop: my language at the base beats English at the realm', function (): void {
    ladderLang([
        'en/tenant/messages.php' => ['a' => 'English realm word'],
        'es/messages.php' => ['a' => 'palabra genérica'],
    ]);
    app()->setLocale('es');

    expect(app('translator')->getIn('tenant', 'messages.a'))->toBe('palabra genérica');
});

it('falls through a missing rung to the base', function (): void {
    ladderLang(['en/messages.php' => ['a' => 'base a']]);

    expect(app('translator')->getIn('operator', 'messages.a'))->toBe('base a');
});

it('lets a host override a package line, at the base and at a realm rung', function (): void {
    ladderLang([
        'vendor/probe/en/messages.php' => ['a' => 'host a'],
        'vendor/probe/en/tenant/messages.php' => ['b' => 'host tenant b'],
    ], [
        'en/messages.php' => ['a' => 'package a', 'b' => 'package b'],
    ]);

    $t = app('translator');
    expect(__('probe::messages.a'))->toBe('host a')
        ->and($t->getIn('tenant', 'probe::messages.b'))->toBe('host tenant b')
        ->and($t->getIn('operator', 'probe::messages.b'))->toBe('package b');
});

it('answers two realms differently in one process, and the request\'s realm is read per request', function (): void {
    ladderLang([
        'en/messages.php' => ['greet' => 'Hello'],
        'en/tenant/messages.php' => ['greet' => 'Hello, workspace'],
        'en/operator/messages.php' => ['greet' => 'Hello, operator'],
    ]);
    Route::get('/ladder/{realm}/greet', fn () => __('messages.greet'));
    Route::get('/ladder/greet', fn () => __('messages.greet'));

    $this->get('/ladder/tenant/greet')->assertSee('Hello, workspace');
    $this->get('/ladder/operator/greet')->assertSee('Hello, operator');
    // A request with no realm reads the base: nothing of the previous request's realm is kept.
    $this->get('/ladder/greet')->assertSee('Hello')->assertDontSee('operator');
});

it('selects a plural by the rules of the locale that answered (regional to parent)', function (): void {
    ladderLang([
        'en/messages.php' => ['items' => 'one item|:count items'],
        'ru/messages.php' => ['items' => ':count предмет|:count предмета|:count предметов'],
    ]);
    app()->setLocale('ru-RU');

    expect(trans_choice('messages.items', 1))->toBe('1 предмет')
        ->and(trans_choice('messages.items', 3))->toBe('3 предмета')
        ->and(trans_choice('messages.items', 5))->toBe('5 предметов');
});

it('selects an English line by English rules when a language with other rules falls back to it', function (): void {
    ladderLang(['en/messages.php' => ['items' => 'one item|:count items']]);

    app()->setLocale('ja');
    expect(trans_choice('messages.items', 1))->toBe('one item')
        ->and(trans_choice('messages.items', 2))->toBe('2 items');

    app()->setLocale('ru');
    expect(trans_choice('messages.items', 5))->toBe('5 items');
});

it('gives a validation message the ladder', function (): void {
    ladderLang([
        'es/validation.php' => ['required' => 'El campo :attribute es obligatorio.'],
        'es/tenant/validation.php' => ['required' => 'Falta :attribute en este espacio.'],
    ]);
    app()->setLocale('es-MX');

    expect(Validator::make([], ['name' => 'required'])->errors()->first('name'))
        ->toBe('El campo name es obligatorio.');

    Route::get('/ladder/{realm}/validate', fn () => Validator::make([], ['name' => 'required'])->errors()->first('name'));
    $this->get('/ladder/tenant/validate')->assertSee('Falta name en este espacio.');
});

/*
 * Ruling 05 §2: a realm's configured `label` is that realm's ENGLISH rung line. Cases A–E and F′, with the flagship's
 * `tokens` (declared "API tokens"; realm `user` configured "Personal Access Tokens"). A miss returns the key: the
 * declared literal is the emit point's default (ticket 08), not the translator's.
 */
function tokensConfigured(string $label = 'Personal Access Tokens'): void
{
    app(RealmResourceRegistry::class)->override('tokens', 'user', new RealmResourceOverride(label: $label));
}

it('case A: config only', function (): void {
    ladderLang([]);
    tokensConfigured();
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('Personal Access Tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('Personal Access Tokens')
        ->and($t->getIn('tenant', 'resources.tokens.label', locale: 'en'))->toBe('resources.tokens.label');
});

it('case B: a base translation beats realm wording in English', function (): void {
    ladderLang(['es/resources.php' => ['tokens' => ['label' => 'Tokens de API']]]);
    tokensConfigured();
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('Personal Access Tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('Tokens de API')
        ->and($t->getIn('tenant', 'resources.tokens.label', locale: 'en'))->toBe('resources.tokens.label');
});

it('case C: a realm rung in a second language', function (): void {
    ladderLang(['es/user/resources.php' => ['tokens' => ['label' => 'Tokens de acceso personal']]]);
    tokensConfigured();
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('Personal Access Tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('Tokens de acceso personal');
});

it('case D: the realm\'s word beats a host\'s generic English override', function (): void {
    ladderLang(['en/resources.php' => ['tokens' => ['label' => 'Access tokens']]]);
    tokensConfigured();
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('Personal Access Tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('Personal Access Tokens')
        ->and($t->getIn('tenant', 'resources.tokens.label', locale: 'en'))->toBe('Access tokens');
});

it('case E: a same-realm English rung file supersedes the config', function (): void {
    ladderLang(['en/user/resources.php' => ['tokens' => ['label' => 'Personal tokens']]]);
    tokensConfigured();
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('Personal tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('Personal tokens')
        ->and($t->getIn('tenant', 'resources.tokens.label', locale: 'en'))->toBe('resources.tokens.label');
});

it('case F′: a config label equal to the declaration still pins its realm once a catalog exists', function (): void {
    ladderLang(['en/resources.php' => ['tokens' => ['label' => 'Access tokens']]]);
    tokensConfigured('API tokens');
    $t = app('translator');

    expect($t->getIn('user', 'resources.tokens.label', locale: 'en'))->toBe('API tokens')
        ->and($t->getIn('user', 'resources.tokens.label', locale: 'es'))->toBe('API tokens')
        ->and($t->getIn('tenant', 'resources.tokens.label', locale: 'en'))->toBe('Access tokens');
});

it('prints every rung tried for a key and the one that answered', function (): void {
    ladderLang([
        'en/messages.php' => ['a' => 'base a'],
        'es/operator/messages.php' => ['a' => 'operador a'],
    ]);

    $this->artisan('beam:lang:rungs', ['key' => 'messages.a', '--locale' => 'es-MX', '--realm' => 'studio'])
        ->expectsOutputToContain('ANSWERED')
        ->expectsOutputToContain('Answered by realm rung [operator] from a lang file (sourceLocale es): operador a')
        ->assertSuccessful();
});
