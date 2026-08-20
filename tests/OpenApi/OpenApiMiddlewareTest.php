<?php

namespace Splicewire\Beam\Tests\OpenApi;

use Splicewire\Beam\Tests\TestCase;

/**
 * The artifact routes are PUBLIC by default (ADR-0211 §5) — a docs surface nobody can read is not a docs
 * surface, and the real contract boundary is the published scribe stub's `api/*` match rules, not the
 * route. A host that wants the spec behind auth says so in config, exactly as it does for the intake door.
 *
 * Middleware is read when the routes are MOUNTED, so it has to be set pre-boot — hence its own class.
 */
class OpenApiMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('beam.core.openapi.middleware', ['auth']);
    }

    public function test_configured_middleware_is_applied_to_both_routes(): void
    {
        foreach (['beam.openapi.yaml', 'beam.openapi.json'] as $name) {
            $route = $this->app['router']->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is not mounted.");
            $this->assertContains('auth', $route->gatherMiddleware());
        }
    }
}
