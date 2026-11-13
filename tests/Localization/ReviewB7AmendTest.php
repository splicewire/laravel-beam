<?php

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;
use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * REVIEW-B7 (Codex, review-r1): the one beam amend for wave 2b. Each case is the review's repro, plus the coverage it
 * asked for:
 * - B1: the brand slot reads `beam.localization.brand_default`;
 * - B2: the nav-section catalog keeps every owner's key.
 * S1 (12f's census) is in ReviewB7CensusTest. beam-ux's U1 and U2 are in that package.
 */

function b7Brand(?string $default, array $supported = ['en', 'es']): void
{
    config(['beam.localization.supported' => $supported, 'beam.localization.brand_default' => $default, 'app.locale' => 'en']);
}

describe('B1: the brand default is a config-backed source', function (): void {
    it('delivers in the configured brand language when no person or tenant states one', function (): void {
        b7Brand('es');

        $resolution = app(LocaleResolver::class)->forDelivery();

        expect($resolution->locale)->toBe('es')->and($resolution->source)->toBe('brand');
    });

    it('resolves a request with no stated preference to the brand language', function (): void {
        b7Brand('es');

        $resolution = app(LocaleResolver::class)->forRequest(Request::create('/'));

        expect($resolution->locale)->toBe('es')->and($resolution->source)->toBe('brand');
    });

    it('states no language when unconfigured, so the application default answers', function (): void {
        b7Brand(null);

        expect(app(BrandLocaleSource::class)->locale(null))->toBeNull()
            ->and(app(LocaleResolver::class)->forDelivery()->source)->toBe('default');
    });

    it('lets the resolver refuse an unsupported brand language', function (): void {
        b7Brand('fr');

        $resolution = app(LocaleResolver::class)->forDelivery();

        expect($resolution->locale)->toBe('en')->and($resolution->source)->toBe('default');
    });

    it('treats a blank configured value as none', function (): void {
        b7Brand('  ');

        expect(app(BrandLocaleSource::class)->locale(null))->toBeNull();
    });
});

describe('B2: the nav-section catalog keeps every owner', function (): void {
    it('catalogs equal section keys and labels under each actual owner', function (): void {
        $sections = app(NavSectionRegistry::class);
        foreach (['tenant' => 'splicewire/laravel-beam', 'operator' => 'rushing/laravel-data-filters'] as $realm => $by) {
            $sections->register(new NavSection(key: 'review-billing', realm: $realm, label: 'Billing', icon: 'Bank', href: '/billing',
                order: 10, entitlement: null, permission: null, audience: NavAudience::Product), by: $by);
        }

        $keys = app(KeyCatalog::class)->byKey();

        expect($keys)->toHaveKey('beam::nav.sections.review-billing.label')
            ->toHaveKey('data-filters::nav.sections.review-billing.label');
    });

    it('still lists one key once when two realms share an owner', function (): void {
        $sections = app(NavSectionRegistry::class);
        foreach (['tenant', 'operator'] as $realm) {
            $sections->register(new NavSection(key: 'review-ledger', realm: $realm, label: 'Ledger', icon: 'Bank', href: '/ledger',
                order: 10, entitlement: null, permission: null, audience: NavAudience::Product), by: 'splicewire/laravel-beam');
        }

        $catalog = app(KeyCatalog::class);
        $matching = array_filter($catalog->entries(), static fn ($entry): bool => $entry->key === 'beam::nav.sections.review-ledger.label');

        expect($matching)->toHaveCount(1)->and($catalog->conflicts())->not->toHaveKey('beam::nav.sections.review-ledger.label');
    });

    it('reports one owner\'s two labels for one key as a conflict, not a silent pick', function (): void {
        $sections = app(NavSectionRegistry::class);
        foreach (['tenant' => 'Ledger', 'operator' => 'Books'] as $realm => $label) {
            $sections->register(new NavSection(key: 'review-books', realm: $realm, label: $label, icon: 'Bank', href: '/books',
                order: 10, entitlement: null, permission: null, audience: NavAudience::Product), by: 'splicewire/laravel-beam');
        }

        expect(app(KeyCatalog::class)->conflicts())->toHaveKey('beam::nav.sections.review-books.label');
    });
});

describe('U2, beam\'s side: a merged seat keeps each static row\'s contributor', function (): void {
    it('carries every row\'s registrant through the merge, and the header\'s stays the header seat\'s', function (): void {
        $sections = app(NavSectionRegistry::class);
        $seat = static fn (array $static): NavSection => new NavSection(key: 'review-billing', realm: 'tenant', label: 'Billing', icon: 'Bank',
            href: '/billing', order: 10, entitlement: null, permission: null, audience: NavAudience::Product, static: $static);
        $sections->register($seat([['title' => 'Invoices', 'href' => '/invoices', 'routeName' => 'invoices.index']]), by: 'splicewire/laravel-beam');
        $sections->register($seat([['title' => 'Usage', 'href' => '/usage', 'routeName' => 'usage.index']]), by: 'splicewire/laravel-beam-ux');
        $sections->register($seat([['title' => 'Plan', 'href' => '/plan', 'routeName' => 'plan.show']]), by: null);

        $merged = array_values(array_filter($sections->for('tenant'), static fn (NavSection $s): bool => $s->key === 'review-billing'));

        expect($merged)->toHaveCount(1)
            ->and($merged[0]->registrant())->toBe('splicewire/laravel-beam')
            ->and(array_column($merged[0]->static, 'title'))->toBe(['Invoices', 'Usage', 'Plan'])
            ->and([$merged[0]->staticRowRegistrant(0), $merged[0]->staticRowRegistrant(1), $merged[0]->staticRowRegistrant(2)])
            ->toBe(['splicewire/laravel-beam', 'splicewire/laravel-beam-ux', null]);
    });

    it('answers an unmerged seat\'s rows with the seat\'s own registrant', function (): void {
        $section = new NavSection(key: 'review-solo', realm: 'tenant', label: 'Solo', icon: 'Bank', href: '/solo', order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product, static: [['title' => 'One', 'href' => '/one', 'routeName' => 'one']]);
        app(NavSectionRegistry::class)->register($section, by: 'splicewire/laravel-beam-ux');

        expect($section->staticRowRegistrant(0))->toBe('splicewire/laravel-beam-ux');
    });
});
