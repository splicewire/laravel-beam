<?php

namespace Splicewire\Beam\Localization\Http;

use Closure;
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
 * Under the opt-in localized HTTP profile (`beam.localization.http_profile`), the response also states its language:
 * `Content-Language`, `Vary: Accept-Language` merged into any existing Vary, and `Cache-Control: private` when the
 * language came from the person or the workspace, unless a stricter `no-store` is already set.
 */
final class ResolveLocale
{
    public function __construct(private LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolver->forRequest($request, reduced: ! $request->hasSession());

        $response = LocaleScope::run($resolution->locale, fn () => $next($request));

        if ((bool) config('beam.localization.http_profile', false) && $response instanceof Response) {
            $this->stateLanguage($response, $resolution);
        }

        return $response;
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
