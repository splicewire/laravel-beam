<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Http\ResolveLocale;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(TestCase::class);

/*
 * localization 06 — every HTTP request answers inside a locale scope (ruling 03 §2, §5). ResolveLocale is global, so a
 * 404 or an early 401 renders in the reduced resolver's language, and at the end of the `web` and `api` groups, so a
 * signed-in person's preference counts. The opt-in HTTP profile states the language on the response.
 */

beforeEach(function (): void {
    config([
        'app.key' => str_repeat('l', 32),
        'app.locale' => 'en',
        'beam.localization.supported' => ['en', 'es', 'fr'],
        'beam.localization.http_profile' => true,
    ]);
    app()->setLocale('en');

    // Each rendered body says which language it rendered in.
    $handler = app(ExceptionHandler::class);
    $handler->renderable(fn (NotFoundHttpException $e) => response('missing in '.app()->getLocale(), 404));
    $handler->renderable(fn (AuthenticationException $e) => response('signed out in '.app()->getLocale(), 401));

    // Explicitly PUBLIC responses: Symfony answers `no-cache, private` for a response that states no caching, which
    // would make the private assertions below prove nothing.
    Route::middleware('web')->get('/l10n/web', fn () => response('web in '.app()->getLocale())->setPublic()->setMaxAge(60));
    Route::middleware('web')->get('/l10n/web-no-store', fn () => response('x')->header('Cache-Control', 'no-store, public'));
    Route::middleware('api')->get('/l10n/api', fn () => response('api in '.app()->getLocale())->setPublic()->setMaxAge(60)->header('Vary', 'Origin'));
    Route::get('/l10n/early-401', fn () => throw new AuthenticationException);
});

function personPrefers(?string $tag): void
{
    app()->instance(PersonLocaleSource::class, new class($tag) implements PersonLocaleSource
    {
        public function __construct(private ?string $tag) {}

        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return $this->tag;
        }
    });
}

it('places the middleware globally, first, and at the end of the web and api groups', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();
    $groups = app(Router::class)->getMiddlewareGroups();

    expect($global[0])->toBe(ResolveLocale::class)
        ->and(end($groups['web']))->toBe(ResolveLocale::class)
        ->and(end($groups['api']))->toBe(ResolveLocale::class);
});

it('renders a 404 in the reduced resolver\'s language', function (): void {
    $this->get('/l10n/nowhere', ['Accept-Language' => 'es'])
        ->assertNotFound()
        ->assertSee('missing in es')
        ->assertHeader('Content-Language', 'es');
});

it('renders an early 401 in the reduced resolver\'s language, never a person\'s preference', function (): void {
    personPrefers('fr');

    $this->get('/l10n/early-401', ['Accept-Language' => 'es'])
        ->assertStatus(401)
        ->assertSee('signed out in es');
});

it('restores the application locale after the request', function (): void {
    $this->get('/l10n/nowhere', ['Accept-Language' => 'es']);

    expect(app()->getLocale())->toBe('en')->and(config('app.locale'))->toBe('en');
});

it('lets a browser\'s saved preference answer inside the web group, and marks the response private', function (): void {
    personPrefers('fr');

    $response = $this->get('/l10n/web', ['Accept-Language' => 'es'])->assertOk()->assertSee('web in fr')
        ->assertHeader('Content-Language', 'fr');

    expect($response->headers->getCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('public'))->toBeNull();
});

it('leaves a stricter no-store response as its route wrote it', function (): void {
    personPrefers('fr');

    $response = $this->get('/l10n/web-no-store');

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('private'))->toBeFalse();
});

it('lets an API client\'s header win and merges Vary: Accept-Language once', function (): void {
    personPrefers('fr');

    $response = $this->getJson('/l10n/api', ['Accept-Language' => 'es'])->assertOk()->assertSee('api in es')
        ->assertHeader('Content-Language', 'es');

    $vary = array_map('trim', explode(',', (string) $response->headers->get('Vary')));
    expect($vary)->toContain('Origin')
        ->and(array_count_values(array_map('strtolower', $vary))['accept-language'])->toBe(1)
        ->and($response->headers->hasCacheControlDirective('public'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('private'))->toBeFalse();
});

it('reads and writes no language header with the profile off', function (): void {
    config(['beam.localization.http_profile' => false]);

    $response = $this->get('/l10n/web', ['Accept-Language' => 'es'])->assertSee('web in en');

    expect($response->headers->has('Content-Language'))->toBeFalse()
        ->and(strtolower((string) $response->headers->get('Vary')))->not->toContain('accept-language');
});

it('answers a request naming an unsupported language in the default (06 done-when)', function (): void {
    $this->getJson('/l10n/api', ['Accept-Language' => 'ja, de;q=0.5'])
        ->assertSee('api in en')
        ->assertHeader('Content-Language', 'en');
});

it('answers a request naming a supported language in it, and says so (06 done-when)', function (): void {
    $this->get('/l10n/web', ['Accept-Language' => 'fr-CA, en;q=0.2'])
        ->assertSee('web in fr')
        ->assertHeader('Content-Language', 'fr');
});
