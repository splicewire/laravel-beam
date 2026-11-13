<?php

namespace Splicewire\Beam\Tests\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Routing\CoreRoutes;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

class CoreRoutesTest extends TestCase
{
    public function test_default_root_preserves_the_existing_mount_and_host_boundary(): void
    {
        $router = $this->app->make(Router::class);
        $router->prefix('legacy')->name('legacy.')->middleware('auth')->group(function (): void {
            Particle::mount('hooks')->only(['index', 'show', 'update', 'destroy'])->idConstraint('uuid');
        });
        $legacy = $router->getRoutes()->match(Request::create('/legacy/hooks'));

        $router->domain('workspace.example')->middleware('auth')->group(function (): void {
            CoreRoutes::mount();
        });

        $current = $router->getRoutes()->match(Request::create('http://workspace.example/beam/core/hooks'));
        $this->assertSame($legacy->getActionName(), $current->getActionName());
        $this->assertContains('auth', $current->gatherMiddleware());
        $this->assertSame('workspace.example', $current->getDomain());
        $this->assertSame($legacy, $router->getRoutes()->match(Request::create('/legacy/hooks')));
        $this->assertNotSame($legacy->getName(), $current->getName());
        $this->getJson('http://workspace.example/beam/core/hooks')->assertUnauthorized();
    }

    public function test_root_override_replaces_the_default_without_moving_the_legacy_mount(): void
    {
        $router = $this->app->make(Router::class);
        Particle::mount('hooks')->only(['index'])->idConstraint('uuid');
        config(['beam.core.api_root' => '/custom/core/']);

        CoreRoutes::mount();

        $legacy = $router->getRoutes()->match(Request::create('/hooks'));
        $current = $router->getRoutes()->match(Request::create('/custom/core/hooks'));
        $this->assertSame($legacy->getActionName(), $current->getActionName());
        $this->assertNotContains('beam/core/hooks', array_map(fn ($route) => $route->uri(), iterator_to_array($router->getRoutes())));
    }

    public function test_catalog_and_lifecycle_routes_retain_the_existing_exposure(): void
    {
        CoreRoutes::mount();
        $this->app->make(ResourceDiscoveryAutoMounter::class)->mount();
        $router = $this->app->make(Router::class);
        $catalog = $router->getRoutes()->match(Request::create('/beam/core/hooks/events'));
        $this->assertSame(HookEventCatalogController::class.'@index', $catalog->getActionName());
        $this->assertSame(['resource' => null], $catalog->defaults[HookEventCatalogController::CONFIG]);

        $id = 'c4653fc0-1260-4d9c-8f29-5d9c15d501f2';
        foreach (['realms' => 'beam.core.realms', 'hooks/'.$id.'/deliveries' => 'beam.core.hooks.deliveries', 'hooks/discovery' => 'beam.core.hooks.discovery'] as $uri => $name) {
            $route = $router->getRoutes()->match(Request::create('/beam/core/'.$uri));
            $this->assertSame($name, $route->getName());
        }
        foreach (['GET' => 'show', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'destroy'] as $method => $action) {
            $route = $router->getRoutes()->match(Request::create('/beam/core/hooks/'.$id, $method));
            $this->assertStringEndsWith('@'.$action, $route->getActionName());
        }
        $reset = $router->getRoutes()->match(Request::create('/beam/core/hooks/'.$id.'/reset', 'POST'));
        $this->assertSame('beam.core.hooks.reset', $reset->getName());
        $this->assertNotContains('beam/core/hooks/hooks/events', array_map(fn ($route) => $route->uri(), iterator_to_array($router->getRoutes())));
    }
}
