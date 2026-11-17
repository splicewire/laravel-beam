<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixture (config-sections ticket 07): a request carrying `X-Tenant: <id>` runs as that tenant's hop, the way a tenancy
 * middleware and beam-tenancy's `ConfigLadderTenancyBootstrapper` would run it: enter the tenant, apply the tenant tier;
 * on the way out, revert the tenant tier and leave. Pushed AFTER beam's prepended operator middleware, so the operator
 * sync has already run when the tenant tier applies.
 */
class EnterFakeTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->header('X-Tenant');

        if (! is_string($tenant) || $tenant === '') {
            return $next($request);
        }

        $context = app(FakeTenancyContext::class);
        $context->enter($tenant);
        app(ConfigTenantOverlay::class)->apply();

        try {
            return $next($request);
        } finally {
            app(ConfigTenantOverlay::class)->revert();
            $context->leave();
        }
    }
}
