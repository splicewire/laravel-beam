<?php

use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 06 — the pseudo-locale transform (en-XA): English, marked and padded, parameters, markup and plural
 * conditions preserved, and the translator answering it from the source-language line.
 */

function pseudoLang(array $lines): void
{
    $root = sys_get_temp_dir().'/beam-pseudo-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($lines as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", str_ends_with($path, '.json')
            ? json_encode($content, JSON_UNESCAPED_UNICODE)
            : '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

beforeEach(function (): void {
    config(['beam.localization.supported' => ['en'], 'beam.localization.pseudo' => true]);
});

it('marks, accents and pads a line', function (): void {
    $out = PseudoLocale::transform('Save changes');

    expect($out)->toStartWith(PseudoLocale::OPEN)
        ->toEndWith(PseudoLocale::CLOSE)
        ->toContain('Sávé çháñgés')
        ->and(PseudoLocale::isMarked($out))->toBeTrue()
        ->and(mb_strlen($out))->toBeGreaterThan(mb_strlen('Save changes') + 2);
});

it('keeps two parameters byte-identical, so replacement still works', function (): void {
    $out = PseudoLocale::transform('Out of catalog: :unknown. Legal names: :legal.');

    expect($out)->toContain(':unknown')->toContain(':legal')
        ->and(trans()->replaceIn($out, ['unknown' => 'x.y', 'legal' => 'a.b']))->toContain('x.y')->toContain('a.b');
});

it('keeps markup and each plural branch\'s condition', function (): void {
    expect(PseudoLocale::transform('<strong>Bold</strong> text'))->toContain('<strong>')->toContain('</strong>')
        ->and(PseudoLocale::transform('{0} none|[1,*] :count items'))->toStartWith('{0} '.PseudoLocale::OPEN)
        ->toContain('|[1,*] '.PseudoLocale::OPEN)->toContain(':count');
});

it('leaves an empty line empty', function (): void {
    expect(PseudoLocale::transform(''))->toBe('');
});

it('answers en-XA from the English line, group and JSON alike, and selects plurals', function (): void {
    pseudoLang([
        'en/greetings.php' => ['hello' => 'Hello :name', 'apples' => '{1} one apple|[2,*] :count apples'],
        'en.json' => ['Sign in' => 'Sign in'],
    ]);
    app()->setLocale('en-XA');

    $hello = __('greetings.hello', ['name' => 'Ada']);
    $many = trans_choice('greetings.apples', 3);

    expect(PseudoLocale::isMarked($hello))->toBeTrue()
        ->and($hello)->toContain('Ada')
        ->and(PseudoLocale::isMarked(__('Sign in')))->toBeTrue()
        ->and($many)->toContain('3')->toContain('áppl')
        ->and(trans_choice('greetings.apples', 1))->not->toContain('3');
});

it('marks a declared literal the catalog had no line for, so a walkthrough sees it resolved', function (): void {
    pseudoLang([]);
    app()->setLocale('en-XA');

    expect(PseudoLocale::isMarked(app('translator')->present('Declared default')))->toBeTrue();

    app()->setLocale('en');
    expect(app('translator')->present('Declared default'))->toBe('Declared default');
});

it('leaves hard-coded text unmarked under en-XA, so the walkthrough (ticket 20) finds it', function (): void {
    pseudoLang(['en/greetings.php' => ['hello' => 'Hello']]);
    app()->setLocale('en-XA');

    // One view, two strings: the catalog line passes through the ladder and is marked; the literal never does.
    $html = Illuminate\Support\Facades\Blade::render('<p>{{ __(\'greetings.hello\') }}</p><p>Hard-coded words</p>');

    expect($html)->toContain('<p>Hard-coded words</p>')
        ->and(PseudoLocale::isMarked(__('greetings.hello')))->toBeTrue()
        ->and(str_contains($html, PseudoLocale::OPEN.'Hélló'))->toBeTrue()
        // A key with no line comes back as the key itself: not through the catalog, so not marked either.
        ->and(PseudoLocale::isMarked(__('greetings.missing')))->toBeFalse();
});
