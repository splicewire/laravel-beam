<?php

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Number;
use Splicewire\Beam\Localization\LocaleNegotiator;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * REVIEW-B6 (Codex, review-r1) — the one beam amend for wave 2a. Each case is the review's repro, plus the coverage the
 * review asked for: B1 a deferred emit keeps the EFFECTIVE realm it was captured in; B2 a scope restores the translator's
 * own locale, separately from the application's; S1 an invalid `q` discards its candidate. B3 (12's catalog) is in
 * ReviewB6CatalogTest.
 */

function b6Request(?string $realm): void
{
    $request = Request::create($realm === null ? '/' : '/'.$realm);
    if ($realm !== null) {
        $route = new Route('GET', '/{realm}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
    }
    app()->instance('request', $request);
}

describe('B1: a capture keeps the effective realm', function (): void {
    it('freezes a route-derived realm across a later request in another realm', function (): void {
        b6Request('tenant');
        $translator = app('translator');
        expect($translator->requestRealm())->toBe('tenant');

        $captured = LocaleScope::run('fr', fn () => LocaleScope::capture());
        b6Request('operator');

        expect($captured->enter(fn () => $translator->requestRealm()))->toBe('tenant')
            ->and($captured->enter(fn () => app()->getLocale()))->toBe('fr')
            ->and($translator->requestRealm())->toBe('operator');
    });

    it('freezes the base when captured with no realm, even if the later request has one', function (): void {
        b6Request(null);
        $captured = LocaleScope::run('fr', fn () => LocaleScope::capture());
        b6Request('operator');

        expect($captured->enter(fn () => app('translator')->requestRealm()))->toBeNull();
    });

    it('still prefers an explicitly entered realm over the route', function (): void {
        b6Request('tenant');
        $captured = LocaleScope::runIn('fr', 'operator', fn () => LocaleScope::capture());
        b6Request(null);

        expect($captured->enter(fn () => app('translator')->requestRealm()))->toBe('operator');
    });
});

describe('B2: the translator locale is its own state', function (): void {
    it('restores an independently set translator locale on return', function (): void {
        app()->setLocale('en');
        app('translator')->setLocale('de');

        LocaleScope::run('es', fn () => null);

        expect(app()->getLocale())->toBe('en')->and(app('translator')->getLocale())->toBe('de');
    });

    it('restores it on a throw and through a nested scope', function (): void {
        app()->setLocale('en');
        app('translator')->setLocale('de');

        try {
            LocaleScope::run('es', function (): void {
                LocaleScope::run('fr', fn () => null);
                expect(app('translator')->getLocale())->toBe('es');
                throw new RuntimeException('inner');
            });
        } catch (RuntimeException) {
        }

        expect(app()->getLocale())->toBe('en')
            ->and(app('translator')->getLocale())->toBe('de')
            ->and(Number::defaultLocale())->toBe('en');
    });
});

describe('S1: an invalid q discards its candidate', function (): void {
    it('skips a malformed, an out-of-range or an over-precise q', function (): void {
        expect(LocaleNegotiator::negotiate('fr;q=bogus,en;q=0.8', ['fr', 'en']))->toBe('en')
            ->and(LocaleNegotiator::negotiate('fr;q=1.5,en;q=0.5', ['fr', 'en']))->toBe('en')
            ->and(LocaleNegotiator::negotiate('fr;q=0.1234,en;q=0.1', ['fr', 'en']))->toBe('en')
            ->and(LocaleNegotiator::negotiate('fr;q=1.000,en', ['fr', 'en']))->toBe('fr')
            ->and(LocaleNegotiator::negotiate('fr;q=0.000,en;q=0.2', ['fr', 'en']))->toBe('en');
    });
});

it('leaves Carbon in English under the pseudo-locale (the review cleared this; kept as a guard)', function (): void {
    Carbon::setLocale('de');

    expect(LocaleScope::run('en-XA', fn () => Carbon::getLocale()))->toBe('en');
});
