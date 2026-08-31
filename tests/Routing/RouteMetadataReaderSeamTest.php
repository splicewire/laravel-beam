<?php

namespace Splicewire\Beam\Tests\Routing;

use Illuminate\Routing\Route;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteActionMetadataReader;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Routing\RouteVisibility;
use Splicewire\Beam\Tests\TestCase;

/**
 * The substitutability seam (api-surface-coherence 126).
 *
 * `BeamRouteAction`'s readers were `static`, so a consumer that said `BeamRouteAction::returns($route)`
 * had nowhere a test could hand it a different reader. They are still static — the front door is
 * deliberately retained, because nineteen of the estate's 37 invocations live in `splicewire/tower` and
 * `~/Herd/splicewire-app` and deleting it would make a behaviour-preserving refactor a three-repo flag
 * day. What changed is that the static now delegates to a container-resolved {@see RouteMetadataReader},
 * so the seam exists AT the static as well as around it.
 *
 * These tests are the thing that would have been impossible before: a stub reader is bound, and both a
 * consumer holding an injected reader AND the bare static are observed to follow it.
 */
class RouteMetadataReaderSeamTest extends TestCase
{
    public function test_the_default_reader_is_bound_and_is_the_action_reader(): void
    {
        $this->assertInstanceOf(
            RouteActionMetadataReader::class,
            $this->app->make(RouteMetadataReader::class),
        );
    }

    /**
     * The `…If` half of `singletonIf` is what makes this a seam rather than a hardcoded default, and it
     * is the one property a reader of the provider comment has to take on trust otherwise: a host that
     * bound its own reader BEFORE beam's provider registers must still win.
     *
     * `getPackageProviders()` runs after testbench's app is built, so binding in `resolveApplication`'s
     * wake is the closest reachable approximation of a host provider that got there first — the
     * assertion that matters is that beam's registration does not overwrite an existing binding.
     */
    public function test_a_reader_bound_before_beams_registration_is_not_overwritten(): void
    {
        $app = $this->app;

        $app->forgetInstance(RouteMetadataReader::class);
        $app->bind(RouteMetadataReader::class, StubRouteMetadataReader::class);

        (new BeamServiceProvider($app))->packageRegistered();

        $this->assertInstanceOf(StubRouteMetadataReader::class, $app->make(RouteMetadataReader::class));
    }

    /**
     * The objection this ticket answers, stated as an assertion: rebinding the reader changes what the
     * static says. Against the pre-126 code there is no interface to bind and the static reads the
     * route's action array unconditionally, so this cannot pass.
     */
    public function test_the_static_front_door_follows_a_rebound_reader(): void
    {
        $route = new Route(['GET'], 'unstamped', ['uses' => fn () => null]);

        $this->assertNull(BeamRouteAction::returns($route));
        $this->assertNull(BeamRouteAction::visibility($route));
        $this->assertNull(BeamRouteAction::resourceKey($route));

        $this->app->instance(RouteMetadataReader::class, new StubRouteMetadataReader);

        $this->assertSame('Stubbed\\Data', BeamRouteAction::returns($route));
        $this->assertTrue(BeamRouteAction::returnsMany($route));
        $this->assertSame(['stubbed' => ['Stubbed\\Frame']], BeamRouteAction::streams($route));
        $this->assertSame(RouteVisibility::Deprecated, BeamRouteAction::visibility($route));
        $this->assertSame('stubbed-op', BeamRouteAction::operationId($route));
        $this->assertSame('stubbed', BeamRouteAction::resourceKey($route));
    }

    /**
     * A consumer that takes the reader as a constructor dependency is substitutable WITHOUT touching the
     * container — the point of an injected seam over service location. `ResourceMountMap` keys its whole
     * map off `resourceKey()`, so a stub that answers `stubbed` for everything puts an otherwise
     * unstamped route into a `stubbed` mount.
     */
    public function test_an_injected_consumer_takes_a_reader_handed_to_it_directly(): void
    {
        $router = $this->app->make('router');
        $router->get('widgets', fn () => null)->name('widgets.index');
        $router->getRoutes()->refreshNameLookups();

        $resourcesOf = fn (ResourceMountMap $map) => array_map(
            fn ($mount) => $mount->resource,
            $map->mounts(),
        );

        $stock = new ResourceMountMap($router, new RouteActionMetadataReader);
        $this->assertNotContains('stubbed', $resourcesOf($stock));

        $stubbed = new ResourceMountMap($router, new StubRouteMetadataReader);
        $this->assertContains('stubbed', $resourcesOf($stubbed));
    }
}

/** Answers a fixed value for every reader, so any consumer reading through the seam is observable. */
class StubRouteMetadataReader implements RouteMetadataReader
{
    public function returns(Route $route): ?string
    {
        return 'Stubbed\\Data';
    }

    public function returnsMany(Route $route): bool
    {
        return true;
    }

    public function streams(Route $route): array
    {
        return ['stubbed' => ['Stubbed\\Frame']];
    }

    public function visibility(Route $route): ?RouteVisibility
    {
        return RouteVisibility::Deprecated;
    }

    public function operationId(Route $route): ?string
    {
        return 'stubbed-op';
    }

    public function resourceKey(Route $route): ?string
    {
        return 'stubbed';
    }
}
