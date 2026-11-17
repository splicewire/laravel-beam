<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;
use Splicewire\Beam\Localization\Http\ResolveLocale;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(TestCase::class);

/*
 * localization 45: the person rung reaches bearer-authenticated API requests. A route's own authentication (`auth:sanctum`
 * on a host) is route middleware, which runs after the `api` group's ResolveLocale, and a bearer request carries no session,
 * so before this the person was unknown when the locale resolved. The token guard here stands in for Sanctum's: it
 * authenticates from the bearer token alone, as a stateless API request does.
 */

beforeEach(function (): void {
    config([
        'app.key' => str_repeat('l', 32),
        'app.locale' => 'en',
        'beam.localization.supported' => ['en', 'es', 'fr'],
        'beam.localization.http_profile' => true,
        'auth.guards.l45' => ['driver' => 'l45-token'],
    ]);
    app()->setLocale('en');

    // A bearer token guard: `saved` is a person who chose French, `none` one who chose nothing, anything else nobody.
    Auth::viaRequest('l45-token', fn (Request $request) => match ($request->bearerToken()) {
        'saved' => new GenericUser(['id' => 1, 'locale' => 'fr']),
        'none' => new GenericUser(['id' => 2, 'locale' => null]),
        default => null,
    });

    // The person rung reads the request's signed-in person, as beam-accounts' PreferredLocaleSource does, and records
    // each answer it gave, so a test can tell "asked and found nothing" from "never asked".
    app()->instance(PersonLocaleSource::class, $this->person = new class implements PersonLocaleSource
    {
        /** @var list<array{user: mixed, answer: ?string}> */
        public array $asked = [];

        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            $user = $request?->user();
            $answer = $user?->locale;
            $this->asked[] = ['user' => $user?->getAuthIdentifier(), 'answer' => $answer];

            return $answer;
        }
    });

    $handler = app(ExceptionHandler::class);
    $handler->renderable(fn (AuthenticationException $e) => response('signed out in '.app()->getLocale(), 401));
    $handler->renderable(fn (NotFoundHttpException $e) => response('missing in '.app()->getLocale(), 404));

    // A route-model binding that finds nothing: SubstituteBindings answers 404 for it.
    Route::bind('l45thing', fn (string $value) => abort(404));
    Route::middleware(['api', 'auth:l45'])->get('/l45/things/{l45thing}', fn () => response('never'));

    Route::middleware(['api', 'auth:l45'])->get('/l45/api', fn () => response('api in '.app()->getLocale()));
    Route::middleware('api')->get('/l45/public', fn () => response('public in '.app()->getLocale()));
});

/**
 * A bearer client that names no language. The test client otherwise sends Symfony's default `Accept-Language`
 * (`en-us,en;q=0.5`), which under the HTTP profile answers at the API-header rung, before the person.
 *
 * @return array<string, string>
 */
function bearer(string $token, string $language = ''): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept-Language' => $language];
}

function tenantDefaults(string $tag): void
{
    app()->instance(TenantLocaleSource::class, new class($tag) implements TenantLocaleSource
    {
        public function __construct(private string $tag) {}

        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return $this->tag;
        }
    });
}

it('sorts ResolveLocale right after authentication in the middleware priority', function (): void {
    $priority = app(Kernel::class)->getMiddlewarePriority();
    $at = array_search(ResolveLocale::class, $priority, true);

    expect($at)->not->toBeFalse()
        ->and($at)->toBe(array_search(AuthenticatesRequests::class, $priority, true) + 1)
        ->and($at)->toBeGreaterThan(array_search(StartSession::class, $priority, true));
});

it('runs a route\'s own authentication before the api group\'s ResolveLocale', function (): void {
    $router = app(Router::class);
    $middleware = $router->gatherRouteMiddleware($router->getRoutes()->match(Request::create('/l45/api')));
    $names = array_map(fn ($m) => is_string($m) ? $m : get_class($m), $middleware);

    $auth = array_key_first(array_filter($names, fn (string $m) => str_starts_with($m, Illuminate\Auth\Middleware\Authenticate::class)));
    $locale = array_search(ResolveLocale::class, $names, true);

    expect($auth)->not->toBeNull()->and($locale)->not->toBeFalse()->and($auth)->toBeLessThan($locale);
});

it('answers a bearer request in the authenticated person\'s saved language', function (): void {
    $this->get('/l45/api', bearer('saved'))
        ->assertOk()
        ->assertSee('api in fr')
        ->assertHeader('Content-Language', 'fr');
});

it('falls through to the next rung when the bearer request\'s person saved no language', function (): void {
    tenantDefaults('es');

    $this->get('/l45/api', bearer('none'))
        ->assertOk()
        ->assertSee('api in es')
        ->assertHeader('Content-Language', 'es');

    // Asked, and found nothing: the person rung ran for the authenticated person and fell through, rather than never
    // being asked.
    expect($this->person->asked)->toContain(['user' => 2, 'answer' => null]);
});

it('keeps the order of the other rungs: an API client\'s header still answers before the person', function (): void {
    $this->get('/l45/api', bearer('saved', 'es'))
        ->assertOk()
        ->assertSee('api in es');
});

it('never consults the person for a guest API request', function (): void {
    // A person source that would answer for anyone: a guest request must not reach it (the reduced resolver).
    app()->instance(PersonLocaleSource::class, new class implements PersonLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return 'fr';
        }
    });

    $this->get('/l45/public', ['Accept-Language' => ''])->assertOk()->assertSee('public in en');
});

it('refuses an unauthenticated bearer request in the reduced resolver\'s language, never a person\'s', function (): void {
    // A person source that would answer for anyone, and no client language: only a reduced resolution gives the default.
    app()->instance(PersonLocaleSource::class, new class implements PersonLocaleSource
    {
        public function locale(?Request $request, mixed $recipient = null): ?string
        {
            return 'fr';
        }
    });

    $this->getJson('/l45/api', bearer('wrong'))
        ->assertStatus(401)
        ->assertSee('signed out in en');
});

it('renders a bearer request\'s route-binding 404 in the person\'s language', function (): void {
    // ResolveLocale now sorts ahead of SubstituteBindings, so a binding that finds nothing answers inside the person's
    // scope rather than the global (reduced) one.
    $this->get('/l45/things/42', bearer('saved'))
        ->assertNotFound()
        ->assertSee('missing in fr');
});

it('sorts authentication first even in a host\'s own api group order', function (): void {
    // A host that lists ResolveLocale in its own group, then authenticates on the route: the priority still sorts it.
    app(Router::class)->middlewareGroup('host-api', [ResolveLocale::class]);
    Route::middleware(['host-api', 'auth:l45'])->get('/l45/host-api', fn () => response('host in '.app()->getLocale()));

    $this->get('/l45/host-api', bearer('saved'))->assertOk()->assertSee('host in fr');
});
