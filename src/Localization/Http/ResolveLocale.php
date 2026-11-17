<?php

namespace Splicewire\Beam\Localization\Http;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Splicewire\Beam\Localization\LocaleResolution;
use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Localization\LocaleScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a request in its resolved language (localization 06, ruling 03 §2, §5). Beam places it twice:
 *
 * - **globally**, before routing and before the session exists, so a 404, a 419 or an early 401 renders inside a scope.
 *   There is no session there yet, so this is the reduced resolver: the supported header and the defaults, never an
 *   unavailable person's preference;
 * - **at the end of the `web` and `api` groups**, after the session has started, so the person's saved preference and the
 *   request's credential are known. It nests inside the global scope and restores to it.
 *
 * The group placement also sits in the kernel's middleware PRIORITY, right after authentication (localization 45). A
 * route's own `auth:sanctum` (or any {@see \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests}) is route
 * middleware, which otherwise runs after the group's; the priority sorts it ahead of this middleware. So a
 * bearer-authenticated API request's person is known here. That request carries no session, so the person rung is
 * consulted when a guard has ALREADY authenticated the request ({@see \Illuminate\Contracts\Auth\Guard::hasUser()}), never by asking a guard to
 * authenticate one. A request with neither a session nor an authenticated user, the global pass included, stays reduced.
 *
 * Under the opt-in localized HTTP profile (`beam.localization.http_profile`), the response also states its language:
 * `Content-Language`, `Vary: Accept-Language` merged into any existing Vary, and `Cache-Control: private` when the
 * language came from the person or the workspace, unless a stricter `no-store` is already set.
 */
final class ResolveLocale
{
    public function __construct(private LocaleResolver $resolver, private AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolver->forRequest($request, reduced: ! $request->hasSession() && ! $this->authenticated());

        $response = LocaleScope::run($resolution->locale, fn () => $next($request));

        if ((bool) config('beam.localization.http_profile', false) && $response instanceof Response) {
            $this->stateLanguage($response, $resolution);
        }

        return $response;
    }

    /**
     * Whether an authentication middleware has already put a user on the default guard (`Authenticate` makes the guard
     * it authenticated with the default, `shouldUse`). `hasUser()` reads only what is already there: it never runs a
     * guard's own lookup (a token query, a remember-me cookie), so a request nothing authenticated stays reduced.
     *
     * No guard is CREATED to ask. Creating the session guard resolves `session.store`, which the global pass runs before
     * tenancy initialization and StartSession, so a request where no guard was resolved yet answers false without
     * touching one. An auth factory that is not Laravel's manager cannot say what it resolved, so it is never asked.
     */
    private function authenticated(): bool
    {
        return $this->auth instanceof AuthManager
            && $this->auth->hasResolvedGuards()
            && $this->auth->guard()->hasUser();
    }

    private function stateLanguage(Response $response, LocaleResolution $resolution): void
    {
        // The inner (group) scope states the language first; the outer (global) one keeps it.
        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', $resolution->locale);
        }

        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        if (! in_array('accept-language', array_map('strtolower', $vary), true)) {
            $vary[] = 'Accept-Language';
        }
        $response->headers->set('Vary', implode(', ', $vary));

        if ($resolution->personal() && ! $response->headers->hasCacheControlDirective('no-store')) {
            $response->headers->addCacheControlDirective('private');
            $response->headers->removeCacheControlDirective('public');
        }
    }
}
