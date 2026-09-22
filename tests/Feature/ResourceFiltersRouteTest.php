<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Schemastud\Frame\Http\Controllers\FrameResourceFiltersController;
use Schemastud\Frame\Routing\ResourceRoutes;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Tests\TestCase;

/** Metadata uses Frame's capability mount; saved views use their declared resource CRUD. */
class ResourceFiltersRouteTest extends TestCase
{
    public function test_canonical_mount_exposes_only_metadata_and_preserves_host_context(): void
    {
        Route::prefix('{realm}/catalog')->middleware('auth')->group(function (): void {
            ResourceRoutes::filters(at: '{resource}', names: 'catalog', defaults: ['shell' => 'operator']);
        });
        foreach (['schema' => 'schema', 'variants' => 'variants', 'options/status' => 'options', 'alternate/schema' => 'variant-schema'] as $suffix => $name) {
            $route = Route::getRoutes()->match(Request::create('/tenant/catalog/papers/filters/'.$suffix));
            $this->assertSame('catalog.filters.'.$name, $route->getName());
            $this->assertStringStartsWith(FrameResourceFiltersController::class.'@', $route->getActionName());
            $this->assertSame('operator', $route->defaults['shell']);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertSame('tenant', $route->parameter('realm'));
            $this->assertSame('papers', $route->parameter('resource'));
            $this->assertSame(SubSurface::FILTERS, SubSurface::of($route));
        }
        $this->getJson('tenant/catalog/papers/filters')->assertNotFound();
        $this->postJson('tenant/catalog/papers/filters', [])->assertNotFound();
        $this->putJson('tenant/catalog/papers/filters/00000000-0000-4000-8000-000000000000', [])->assertNotFound();
        $this->deleteJson('tenant/catalog/papers/filters/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_canonical_metadata_keeps_one_discovery_root_for_a_stamped_exposure(): void
    {
        ResourceRoutes::filters(at: 'tenant/catalog/papers', names: 'papers', defaults: [
            ParticleController::RESOURCE => 'papers', 'resource' => 'papers',
        ]);
        $mounts = array_values(array_filter(app(ResourceMountMap::class)->mounts(), fn ($mount) => $mount->resource === 'papers'));
        $this->assertCount(1, $mounts);
        $this->assertSame('tenant/catalog/papers', $mounts[0]->root);
        $this->assertCount(4, $mounts[0]->routes);
        foreach ($mounts[0]->routes as $route) {
            $this->assertSame(SubSurface::FILTERS, SubSurface::of($route));
        }
    }
}
