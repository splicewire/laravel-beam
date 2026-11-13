<?php

use Carbon\Carbon;
use Illuminate\Support\Number;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Localization\LocalizationContext;
use Splicewire\Beam\Localization\Queue\WithLocaleScope;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 06 — the one helper that sets and restores locale, number formatting, dates and realm together (ruling
 * 03 §4), its deferred capture, and the opt-in job middleware.
 */

/** @return array{app: string, number: string, carbon: string, realm: array{0: bool, 1: ?string}, currency: string} */
function ambient(): array
{
    return [
        'app' => app()->getLocale(),
        'number' => (string) Number::defaultLocale(),
        'carbon' => Carbon::getLocale(),
        'realm' => app(LocalizationContext::class)->realm(),
        'currency' => Number::defaultCurrency(),
    ];
}

beforeEach(function (): void {
    config(['beam.localization.supported' => ['en', 'es', 'fr', 'de']]);
    app()->setLocale('en');
    Number::useLocale('en');
    Number::useCurrency('USD');
});

it('sets the app locale, Number and Carbon inside, and restores each after', function (): void {
    $before = ambient();

    $inside = LocaleScope::run('es', fn (): array => ambient());

    expect($inside['app'])->toBe('es')
        ->and($inside['number'])->toBe('es')
        ->and($inside['carbon'])->toBe('es')
        ->and(ambient())->toBe($before);
});

it('moves Number with the application locale, which the framework does not', function (): void {
    app()->setLocale('fr');

    expect(Number::defaultLocale())->toBe('fr');
});

it('restores its caller\'s state, not the default, when a nested scope throws', function (): void {
    $seen = LocaleScope::run('es', function (): array {
        try {
            LocaleScope::runIn('de', 'acme', function (): never {
                throw new RuntimeException('inner failed');
            });
        } catch (RuntimeException) {
        }

        return ambient();
    });

    expect($seen['app'])->toBe('es')
        ->and($seen['number'])->toBe('es')
        ->and($seen['carbon'])->toBe('es')
        ->and($seen['realm'])->toBe([false, null])
        ->and(ambient()['app'])->toBe('en');
});

it('restores Number to its own saved value, even when it differed from the app locale', function (): void {
    Number::useLocale('de');

    LocaleScope::run('es', fn () => null);

    expect(Number::defaultLocale())->toBe('de')
        ->and(app()->getLocale())->toBe('en');
});

it('never touches currency', function (): void {
    Number::useCurrency('EUR');

    $inside = LocaleScope::run('es', fn (): string => Number::defaultCurrency());

    expect($inside)->toBe('EUR')->and(Number::defaultCurrency())->toBe('EUR');
});

it('enters a realm, a null realm meaning the base, and the translator reads it over the route', function (): void {
    $translator = app('translator');
    expect($translator)->toBeInstanceOf(BeamTranslator::class);

    expect(LocaleScope::runIn('en', 'acme', fn (): ?string => $translator->requestRealm()))->toBe('acme')
        ->and(LocaleScope::runIn('en', 'acme', fn () => LocaleScope::runIn('en', null, fn (): ?string => $translator->requestRealm())))->toBeNull()
        ->and(LocaleScope::runIn('en', 'acme', fn () => LocaleScope::run('es', fn (): ?string => $translator->requestRealm())))->toBe('acme')
        ->and($translator->requestRealm())->toBeNull();
});

it('re-enters a captured locale and realm at a later emit point', function (): void {
    $captured = LocaleScope::runIn('fr', 'acme', fn () => LocaleScope::capture());

    expect(app()->getLocale())->toBe('en');

    $later = $captured->enter(fn (): array => ambient());

    expect($later['app'])->toBe('fr')
        ->and($later['number'])->toBe('fr')
        ->and($later['realm'])->toBe([true, 'acme'])
        ->and(ambient()['app'])->toBe('en');
});

it('runs a job in its own locale and realm, never the worker\'s residue', function (): void {
    app()->setLocale('de'); // a previous job's residue

    $seen = (new WithLocaleScope('fr', 'acme'))->handle(new stdClass, fn (): array => ambient());

    expect($seen['app'])->toBe('fr')
        ->and($seen['realm'])->toBe([true, 'acme'])
        ->and(app()->getLocale())->toBe('de');
});

it('runs a job that names no locale in the application default and the base realm', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    LocaleScope::runIn('es', 'acme', function (): void {
        // Dispatched from inside a request's scope; the job carries nothing.
        // App::setLocale() rewrote config('app.locale') to `es`; the job still enters the host's configured default.
        $seen = (new WithLocaleScope)->handle(new stdClass, fn (): array => ambient());

        expect(config('app.locale'))->toBe('es')
            ->and($seen['app'])->toBe('en')
            ->and($seen['realm'])->toBe([true, null]);
    });

    $seen = (new WithLocaleScope('ja'))->handle(new stdClass, fn (): array => ambient());

    expect($seen['app'])->toBe('en');
});

it('formats a number and a date for each of two successive scopes, and restores the state before the first (06 done-when)', function (): void {
    $before = ambient();
    $date = Carbon::parse('2026-03-05');
    $render = fn (): array => [Number::format(1234.5), $date->copy()->locale(app()->getLocale())->translatedFormat('j F'), Carbon::now()->getLocale()];

    $de = LocaleScope::run('de', $render);
    $es = LocaleScope::run('es', $render);

    expect($de)->toBe(['1.234,5', '5 März', 'de'])
        // Spanish grouping of a four-digit number varies by ICU version; the explicit-locale call is the oracle.
        ->and($es[0])->toBe(Number::format(1234.5, locale: 'es'))->toContain(',5')
        ->and($es[1])->toBe('5 marzo')
        ->and($es[2])->toBe('es')
        ->and(ambient())->toBe($before)
        ->and(Number::format(1234.5))->toBe('1,234.5');
});

it('formats money in the host\'s declared currency under both scopes, never one derived from the locale (06 done-when)', function (): void {
    Number::useCurrency('EUR');

    $de = LocaleScope::run('de', fn (): string => Number::currency(12.5));
    $en = LocaleScope::run('en', fn (): string => Number::currency(12.5));

    expect($de)->toContain('€')->toContain('12,50')
        ->and($en)->toContain('€')->toContain('12.50');
});
