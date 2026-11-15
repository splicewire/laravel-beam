<?php

namespace Splicewire\Beam\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP hook of the operator tier (config-sections ticket 05): before a request runs, re-apply the published config
 * overrides if the published-version counter moved since this process last applied them. Prepended to the GLOBAL
 * middleware stack by beam's provider, so routing, the session and every controller read the current values. A store
 * or cache that cannot be read never fails the request ({@see ConfigOverlay::sync()}).
 */
class ApplyConfigOverrides
{
    public function handle(Request $request, Closure $next): Response
    {
        app(ConfigOverlay::class)->sync();

        return $next($request);
    }
}
