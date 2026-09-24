<?php

namespace Splicewire\Beam\Tests\Discovery;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Reading a mount's middleware must not build its controllers.
 *
 * `Route::gatherMiddleware()` asks the controller for its middleware, which instantiates and CACHES it
 * on the route. `ResourceMountMap::mounts()` runs at boot (the discovery auto-mounter), so every
 * mounted particle controller was built before the first request, with its `ParticleWriter` — and the
 * event dispatcher inside it — frozen as they stood at boot. The flagship's ScaffoldPack pipeline test
 * saw it as `BeamParticlePersisted` never reaching `Event::fake()`.
 */
class ResourceMountMapControllerCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'papers',
            name: 'reindex',
            kind: OperationKind::Task,
            model: 'App\\Models\\Paper',
            handle: fn () => null,
        ));

        Particle::ops('papers', 'papers', 'reindex');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_reading_the_mounts_leaves_no_controller_instantiated_on_the_route(): void
    {
        $route = Route::getRoutes()->getByName('papers.reindex');
        $this->assertNotNull($route);
        $this->assertNull($route->controller);

        $mounts = $this->app->make(ResourceMountMap::class)->mounts();

        $this->assertNotEmpty($mounts, 'the op route must be part of a mount, or this reads nothing');
        $this->assertNull($route->controller, 'the mount map built and kept the route\'s controller');
    }

    public function test_a_controller_the_route_already_held_is_left_in_place(): void
    {
        $route = Route::getRoutes()->getByName('papers.reindex');
        $held = $route->getController();

        $this->app->make(ResourceMountMap::class)->mounts();

        $this->assertSame($held, $route->controller);
    }
}
