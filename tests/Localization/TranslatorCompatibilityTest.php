<?php

use Illuminate\Translation\Translator;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Realm\RealmResourceOverride;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 07, the amend (range review B1): beam's ladder keeps the framework translator's own behaviour wherever
 * the ladder has nothing to add, measured against the installed framework Translator over the same loader:
 *   1. a whole-group read (`get('messages')`, `get('ns::messages')`) returns the group array, realm rungs merged in;
 *   2. a JSON (sentence-keyed) line answers `choice()` as it answers `get()`;
 *   3. a dotted resource key reaches its realm's configured label through its slash-encoded identity (ruling 01 §2).
 * The first three tests are review-r1's probes (REVIEW-B1, ReviewRangeTest.php), reproduced here.
 */

function compatLang(array $files): BeamTranslator
{
    $root = sys_get_temp_dir().'/beam-compat-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", is_array($content) && str_ends_with($path, '.json')
            ? json_encode($content)
            : '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath("{$root}/lang");
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    $t = app('translator');
    $t->addNamespace('probe', "{$root}/probe-lang");
    $t->setFallback('en');

    return $t;
}

function frameworkOracle(BeamTranslator $beam, string $locale = 'en'): Translator
{
    $base = new Translator($beam->getLoader(), $locale);
    $base->setFallback('en');
    $base->addNamespace('probe', $beam->getLoader()->namespaces()['probe'] ?? '/nowhere');

    return $base;
}

// ── review-r1's probes ────────────────────────────────────────────────────────────────────────────

it('review keeps whole-group lookups compatible with the framework', function (): void {
    $beam = compatLang(['lang/en/messages.php' => ['hello' => 'Hello']]);

    expect($beam->get('messages'))->toBe(frameworkOracle($beam)->get('messages'))
        ->and($beam->get('messages'))->toBe(['hello' => 'Hello']);
});

it('review keeps existing JSON plural lookups compatible with the framework', function (): void {
    $beam = compatLang(['lang/en.json' => ['apples' => 'one apple|many apples']]);

    expect($beam->choice('apples', 2))->toBe(frameworkOracle($beam)->choice('apples', 2))
        ->and($beam->choice('apples', 2))->toBe('many apples');
});

it('review reads a dotted resource override through its ruled slash identity', function (): void {
    $beam = compatLang([]);
    app(RealmResourceRegistry::class)->override('finance.loan', 'tenant', new RealmResourceOverride(label: 'Realm loan'));

    expect($beam->getIn('tenant', 'resources.finance/loan.label'))->toBe('Realm loan');
});

// ── the builder's cases beside them ───────────────────────────────────────────────────────────────

it('reads a namespaced whole group like the framework', function (): void {
    $beam = compatLang(['probe-lang/en/messages.php' => ['a' => 'A', 'b' => ['c' => 'C']]]);

    expect($beam->get('probe::messages'))->toBe(frameworkOracle($beam)->get('probe::messages'))
        ->and($beam->get('probe::messages'))->toBe(['a' => 'A', 'b' => ['c' => 'C']]);
});

it('merges a realm rung\'s sparse group over the base for a whole-group read', function (): void {
    $beam = compatLang([
        'lang/en/messages.php' => ['a' => 'base a', 'b' => 'base b'],
        'lang/en/tenant/messages.php' => ['b' => 'tenant b'],
    ]);

    expect($beam->getIn('tenant', 'messages'))->toBe(['a' => 'base a', 'b' => 'tenant b'])
        ->and($beam->getIn(null, 'messages'))->toBe(['a' => 'base a', 'b' => 'base b']);
});

it('answers a whole-group read from the first locale in the chain that has the group', function (): void {
    $beam = compatLang([
        'lang/en/messages.php' => ['a' => 'en a'],
        'lang/es/messages.php' => ['a' => 'es a'],
    ]);

    expect($beam->get('messages', [], 'es-MX'))->toBe(['a' => 'es a'])
        ->and($beam->get('nothing-here'))->toBe('nothing-here');
});

it('replaces :count in a JSON plural and selects by the requested locale\'s rules', function (): void {
    $beam = compatLang([
        'lang/ru.json' => ['items' => ':count предмет|:count предмета|:count предметов'],
    ]);

    expect($beam->choice('items', 5, [], 'ru'))->toBe(frameworkOracle($beam, 'ru')->choice('items', 5, [], 'ru'))
        ->and($beam->choice('items', 5, [], 'ru'))->toBe('5 предметов');
});

it('lets a JSON line answer before a group line, as the framework orders them', function (): void {
    $beam = compatLang([
        'lang/en.json' => ['messages.a' => 'from json'],
        'lang/en/messages.php' => ['a' => 'from group'],
    ]);

    expect($beam->get('messages.a'))->toBe(frameworkOracle($beam)->get('messages.a'))
        ->and($beam->choice('messages.a', 1))->toBe(frameworkOracle($beam)->choice('messages.a', 1));
});

it('still reads an undotted resource\'s configured label', function (): void {
    $beam = compatLang([]);
    app(RealmResourceRegistry::class)->override('tokens', 'user', new RealmResourceOverride(label: 'Personal Access Tokens'));

    expect($beam->getIn('user', 'resources.tokens.label'))->toBe('Personal Access Tokens');
});
