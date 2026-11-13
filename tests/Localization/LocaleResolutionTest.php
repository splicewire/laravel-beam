<?php

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\LocaleNegotiator;
use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\Sources\ConfigBrandLocaleSource;
use Splicewire\Beam\Localization\Sources\NullLocaleSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 06 — where a request's language comes from (ruling 03 §2): the supported list, header negotiation, the
 * resolver's order, classification by credential, the reduced resolver, and delivery without a request (§3).
 */

/** A source that answers one fixed tag, or null. */
function fixedSource(?string $tag): PersonLocaleSource&TenantLocaleSource&BrandLocaleSource
{
    return new class($tag) implements BrandLocaleSource, PersonLocaleSource, TenantLocaleSource
    {
        public function __construct(private ?string $tag) {}

        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return $this->tag;
        }
    };
}

function resolverWith(?string $person = null, ?string $tenant = null, ?string $brand = null): LocaleResolver
{
    return new LocaleResolver(fixedSource($person), fixedSource($tenant), fixedSource($brand));
}

/** A request with the given headers, carrying a session when `$session` (a browser) and not otherwise. */
function localeRequest(array $headers = [], bool $session = false): Request
{
    $request = Request::create('/somewhere', 'GET', server: collect($headers)
        ->mapWithKeys(fn (string $v, string $k): array => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
    // Request::create() sends `Accept-Language: en-us,en;q=0.5` by default; a case states its own header or none.
    if (! array_key_exists('Accept-Language', $headers)) {
        $request->headers->remove('Accept-Language');
    }
    if ($session) {
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));
    }

    return $request;
}

beforeEach(function (): void {
    config([
        'app.locale' => 'en',
        'beam.localization.supported' => ['en', 'es', 'es-MX', 'fr'],
        'beam.localization.fallback' => 'en',
        'beam.localization.http_profile' => true,
    ]);
});

describe('the supported list', function (): void {
    it('defaults to app.locale alone, so an unconfigured host keeps the language it answers in', function (): void {
        config(['beam.localization.supported' => null, 'app.locale' => 'de']);

        expect(Locales::supported())->toBe(['de'])
            ->and(Locales::applicationDefault())->toBe('de');
    });

    it('canonicalizes tags and adds en-XA only when the pseudo-locale is opted in', function (): void {
        config(['beam.localization.supported' => ['en', 'es_mx']]);
        expect(Locales::supported())->toBe(['en', 'es-MX']);

        config(['beam.localization.pseudo' => true]);
        expect(Locales::supported())->toBe(['en', 'es-MX', 'en-XA']);
    });

    it('falls back to the configured fallback when app.locale is not supported', function (): void {
        config(['app.locale' => 'ja']);

        expect(Locales::applicationDefault())->toBe('en');
    });
});

describe('Accept-Language negotiation', function (): void {
    $supported = ['en', 'es', 'es-MX', 'fr'];

    it('picks by q, then by position', function () use ($supported): void {
        expect(LocaleNegotiator::negotiate('fr;q=0.5, es;q=0.9, en;q=0.9', $supported))->toBe('es')
            ->and(LocaleNegotiator::negotiate('de, fr', $supported))->toBe('fr');
    });

    it('reaches a supported parent through the requested tag\'s lineage', function () use ($supported): void {
        expect(LocaleNegotiator::negotiate('es-AR', $supported))->toBe('es')
            ->and(LocaleNegotiator::negotiate('es-mx', $supported))->toBe('es-MX');
    });

    it('never chooses from q=0, a wildcard or a malformed tag', function () use ($supported): void {
        expect(LocaleNegotiator::negotiate('fr;q=0, *', $supported))->toBeNull()
            ->and(LocaleNegotiator::negotiate('<script>, ;;, en-', $supported))->toBeNull()
            ->and(LocaleNegotiator::negotiate(null, $supported))->toBeNull()
            ->and(LocaleNegotiator::negotiate('fr', []))->toBeNull();
    });

    it('never reaches the pseudo-locale from English', function (): void {
        expect(LocaleNegotiator::negotiate('en-US, *', ['en-XA']))->toBeNull()
            ->and(LocaleNegotiator::negotiate('en-XA', ['en', 'en-XA']))->toBe('en-XA');
    });
});

describe('the request resolver', function (): void {
    it('lets an API client\'s header outrank the person\'s saved preference', function (): void {
        $resolution = resolverWith(person: 'fr')->forRequest(localeRequest(['Accept-Language' => 'es']));

        expect([$resolution->locale, $resolution->source])->toBe(['es', 'api-header']);
    });

    it('lets a browser\'s saved preference outrank its header', function (): void {
        $resolution = resolverWith(person: 'fr')->forRequest(localeRequest(['Accept-Language' => 'es'], session: true));

        expect([$resolution->locale, $resolution->source])->toBe(['fr', 'person']);
    });

    it('classifies by credential: a bearer token is an API request even with a session', function (): void {
        $request = localeRequest(['Accept-Language' => 'es', 'Authorization' => 'Bearer abc'], session: true);

        expect(LocaleResolver::isApiRequest($request))->toBeTrue()
            ->and(resolverWith(person: 'fr')->forRequest($request)->source)->toBe('api-header');
    });

    it('classifies a sessionless request as API and a session without a user as a browser', function (): void {
        expect(LocaleResolver::isApiRequest(localeRequest()))->toBeTrue()
            ->and(LocaleResolver::isApiRequest(localeRequest(session: true)))->toBeFalse();
    });

    it('uses a browser\'s header when no person preference is saved', function (): void {
        $resolution = resolverWith()->forRequest(localeRequest(['Accept-Language' => 'es-MX'], session: true));

        expect([$resolution->locale, $resolution->source])->toBe(['es-MX', 'browser-header']);
    });

    it('falls to tenant, then brand, then the application default', function (): void {
        expect(resolverWith(tenant: 'fr', brand: 'es')->forRequest(localeRequest())->source)->toBe('tenant')
            ->and(resolverWith(brand: 'es')->forRequest(localeRequest())->source)->toBe('brand')
            ->and(resolverWith()->forRequest(localeRequest())->locale)->toBe('en');
    });

    it('skips an unsupported answer at every rung', function (): void {
        $resolution = resolverWith(person: 'ja', tenant: 'de', brand: 'es')
            ->forRequest(localeRequest(['Accept-Language' => 'pt'], session: true));

        expect([$resolution->locale, $resolution->source])->toBe(['es', 'brand']);
    });

    it('reads no header with the HTTP profile off', function (): void {
        config(['beam.localization.http_profile' => false]);

        expect(resolverWith()->forRequest(localeRequest(['Accept-Language' => 'es']))->source)->toBe('default')
            ->and(resolverWith(person: 'fr')->forRequest(localeRequest(['Accept-Language' => 'es'], session: true))->source)->toBe('person');
    });

    it('never consults the person in the reduced resolver', function (): void {
        $resolution = resolverWith(person: 'fr')->forRequest(localeRequest(['Accept-Language' => 'es'], session: true), reduced: true);

        expect([$resolution->locale, $resolution->source])->toBe(['es', 'browser-header']);
    });

    it('marks a person or tenant answer personal, and nothing else', function (): void {
        expect(resolverWith(person: 'fr')->forRequest(localeRequest(session: true))->personal())->toBeTrue()
            ->and(resolverWith(tenant: 'fr')->forRequest(localeRequest())->personal())->toBeTrue()
            ->and(resolverWith(brand: 'fr')->forRequest(localeRequest())->personal())->toBeFalse()
            ->and(resolverWith()->forRequest(localeRequest(['Accept-Language' => 'fr']))->personal())->toBeFalse();
    });
});

describe('delivery with no request', function (): void {
    it('reads the recipient, then the tenant, brand and application default', function (): void {
        expect(resolverWith(person: 'fr', tenant: 'es')->forDelivery(new stdClass)->locale)->toBe('fr')
            ->and(resolverWith(tenant: 'es')->forDelivery(new stdClass)->source)->toBe('tenant')
            ->and(resolverWith()->forDelivery()->locale)->toBe('en');
    });
});

it('binds the person and tenant slots to the inert default until their package contributes one, and the brand slot to beam\'s config adapter', function (): void {
    expect(app(PersonLocaleSource::class))->toBeInstanceOf(NullLocaleSource::class)
        ->and(app(TenantLocaleSource::class))->toBeInstanceOf(NullLocaleSource::class)
        ->and(app(BrandLocaleSource::class))->toBeInstanceOf(ConfigBrandLocaleSource::class);
});
