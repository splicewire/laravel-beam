<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Http\ResolveLocale;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 06/36 follow-up (44a): the session-aware ResolveLocale must stay in the `web` and `api` groups when another
 * provider mutates the HTTP kernel after laravel-beam boots. Every kernel mutator ends in Kernel::syncMiddlewareToRouter(),
 * which rewrites the router's groups from the kernel's own lists; laravel-beam-tenancy's prependToMiddlewarePriority() is
 * such a mutator, and on a tenancy host it used to drop a router-only push, so a person's saved language never applied.
 */

beforeEach(function (): void {
    config([
        'app.key' => str_repeat('s', 32),
        'app.locale' => 'en',
        'beam.localization.supported' => ['en', 'es'],
        'beam.localization.http_profile' => true,
    ]);
    app()->setLocale('en');
    Route::middleware('web')->get('/l10n-sync/web', fn () => response('web in '.app()->getLocale()));
});

function syncTestPersonPrefers(?string $tag): void
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

/** How many times ResolveLocale sits in the router's group (what a request actually runs). */
function resolveLocaleCount(string $group): int
{
    return count(array_keys(app(Router::class)->getMiddlewareGroups()[$group] ?? [], ResolveLocale::class, true));
}

/** A late kernel mutation, as laravel-beam-tenancy's makeTenancyMiddlewareHighestPriority() performs after beam boots. */
function lateKernelSync(): void
{
    app(Kernel::class)->prependToMiddlewarePriority(Illuminate\Routing\Middleware\SubstituteBindings::class);
}

it('keeps ResolveLocale in the web and api groups exactly once after a late kernel sync (the tenancy-host shape)', function (): void {
    lateKernelSync();

    expect(resolveLocaleCount('web'))->toBe(1)
        ->and(resolveLocaleCount('api'))->toBe(1)
        ->and(end(app(Router::class)->getMiddlewareGroups()['web']))->toBe(ResolveLocale::class);
});

it('registers ResolveLocale exactly once with no late sync (the beam-starter shape, no tenancy)', function (): void {
    expect(resolveLocaleCount('web'))->toBe(1)
        ->and(resolveLocaleCount('api'))->toBe(1)
        ->and(app(Kernel::class)->getGlobalMiddleware()[0])->toBe(ResolveLocale::class);
});

it('stays idempotent when the localization boot runs again, before and after a sync', function (): void {
    $boot = (new ReflectionMethod(BeamServiceProvider::class, 'bootLocalization'))->getClosure(app()->getProvider(BeamServiceProvider::class));
    $boot();
    lateKernelSync();
    $boot();

    expect(resolveLocaleCount('web'))->toBe(1)
        ->and(resolveLocaleCount('api'))->toBe(1)
        ->and(count(array_keys(app(Kernel::class)->getGlobalMiddleware(), ResolveLocale::class, true)))->toBe(1);
});

it("applies the person's saved language inside the web group after a late kernel sync", function (): void {
    lateKernelSync();
    syncTestPersonPrefers('es');

    $this->get('/l10n-sync/web')->assertOk()->assertSee('web in es');
});
