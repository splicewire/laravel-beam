<?php

namespace Splicewire\Beam\Tests\Discovery;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Ia\Side;
use Splicewire\Beam\Ia\Sides;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough UX-07 (IA-6): the per-mount discovery listing is mounted OUTSIDE the group that produced the mount, so
 * like the mount's common middleware it must also inherit the mount's SIDE. Otherwise a surface served for one side
 * (Sides::serve) ships a listing route that declares none, and the host IA seam's T4 names it `undeclared-side`.
 */
class ResourceMountSideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'pairings',
            name: 'check',
            kind: OperationKind::Task,
            model: 'App\\Models\\Pairing',
            handle: fn () => null,
        ));
    }

    public function test_a_mount_whose_every_route_serves_one_side_carries_that_side_to_its_listing(): void
    {
        config(['beam.core.ia.plays' => ['client']]);
        Sides::serve(Side::Client, 'pairing', fn () => Particle::ops('pairings', 'pairings', 'check'));
        Route::getRoutes()->refreshNameLookups();

        $mount = collect($this->app->make(ResourceMountMap::class)->mounts())->firstWhere('resource', 'pairings');
        $this->assertNotNull($mount);
        $this->assertSame('client', $mount->side);

        $listing = $this->app->make(ParticleMounter::class)->resourceDiscovery($this->app->make(Router::class), $mount);
        $this->assertSame('client', $listing->getAction('side'));
    }

    public function test_a_mount_with_no_declared_side_tags_nothing(): void
    {
        Particle::ops('pairings', 'pairings', 'check');
        Route::getRoutes()->refreshNameLookups();

        $mount = collect($this->app->make(ResourceMountMap::class)->mounts())->firstWhere('resource', 'pairings');
        $this->assertNull($mount->side);

        $listing = $this->app->make(ParticleMounter::class)->resourceDiscovery($this->app->make(Router::class), $mount);
        $this->assertNull($listing->getAction('side'));
    }
}
