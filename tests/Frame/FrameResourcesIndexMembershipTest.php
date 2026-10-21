<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryIndex;
use Schemastud\Frame\Contracts\ResourceAccessGate as FrameResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Schemastud\Frame\Registry\CompositeResourceRegistry;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Authorization\FrameSocketRoute;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Registry-kernel ticket 77 — beam ATTACHES its adapter as a member of `frame.resources` instead of
 * aliasing the port onto it.
 *
 * The tripwire first, twice over. Testbench does not auto-discover, so an index that is not shared is
 * an index every assertion below would pass over (27 D3); and this suite's whole subject is two
 * objects that used to differ, so proving they are one is the first thing to prove.
 */
class FrameResourcesIndexMembershipTest extends TestCase
{
    /**
     * Frame's provider, added for THIS class only.
     *
     * Beam's default list deliberately omits it — ADR-0082's layering law is that beam boots without the
     * frame rung, and `BeamBootTest` asserts it. But `frame.resources` is FRAME's root and frame's
     * provider is what binds the port onto the index, so a suite asking beam whether it joined that index
     * has to boot the rung it is joining. At a real host this is not a choice: `laravel-frame` is a
     * composer dependency and Laravel auto-discovers its provider.
     *
     * AGENTS.md's testbench rule is the reason it is spelled out rather than assumed — an omitted
     * provider here does not fail loudly, it hands back a container that cannot answer, and the natural
     * reading of that error is "beam is not wired" rather than "the harness is not".
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    public function test_the_container_and_the_index_answer_for_frame_resources_with_one_object(): void
    {
        $this->assertSame(app(RegistryIndex::class), app(RegistryIndex::class));

        $port = app(ResourceRegistry::class);

        // Ticket 77's premise, inverted. It measured `ownerOf(...) === app(port)` as FALSE at
        // `~/Herd/splicewire-app`: the port was beam's adapter holding 53 resources and the root's owner
        // was a freshly-constructed, empty `InMemoryResourceRegistry`.
        $this->assertInstanceOf(CompositeResourceRegistry::class, $port);
        $this->assertSame($port, app(RegistryIndex::class)->ownerOf(Key::of('frame.resources')));
    }

    public function test_beams_adapter_is_a_membe_r_and_the_root_never_reads_empty(): void
    {
        $port = app(ResourceRegistry::class);

        $keys = array_map(strval(...), $port->keys());

        $this->assertContains('frame.resources.beam', $keys);
        $this->assertNotSame([], $keys);
        $this->assertInstanceOf(ParticleResourceRegistryAdapter::class, $port->resolve('beam'));

        // The member is the SAME singleton the container hands out — a closure that constructed would
        // hand back a fresh forwarder per read and the boot-time discovery would be invisible.
        $this->assertSame(app(ParticleResourceRegistryAdapter::class), $port->resolve('beam'));
    }

    /**
     * A DTO-less operation subject genuinely exists at `beam.particle.resources` but has no typed Frame
     * resource projection, so `has()` is `false` and `unfiltered()->has()` is `true` — through the adapter,
     * and unchanged through the index.
     */
    public function test_the_projection_stays_narrower_than_the_store_through_the_index(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'operation-only-probe',
            backing: BeamSchema::class,
            label: '',
        ));

        $adapter = app(ParticleResourceRegistryAdapter::class);

        $this->assertFalse($adapter->has('operation-only-probe'));
        $this->assertTrue($adapter->unfiltered()->has('operation-only-probe'));

        $port = app(ResourceRegistry::class);

        $this->assertFalse($port->has('operation-only-probe'));
        $this->assertNull($port->find('operation-only-probe'));
        $this->assertTrue($port->unfiltered()->has('operation-only-probe'));
    }

    public function test_a_framed_resource_routes_through_the_index_to_beams_projection(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'framed-probe',
            backing: BeamSchema::class,
            data: 'Acme\\Data\\FramedProbeData',
            label: 'Framed Probe',
        ));

        $port = app(ResourceRegistry::class);

        $this->assertTrue($port->has('framed-probe'));
        $this->assertSame('framed-probe', $port->get('framed-probe')->key);
        $this->assertContains('framed-probe', array_map(
            fn ($definition): string => $definition->key,
            $port->all(),
        ));
    }

    public function test_an_unlabeled_typed_user_resource_is_directly_accessible_but_not_listed_for_presentation(): void
    {
        $this->actingAs(new GenericUser(['id' => 17, 'name' => 'User Realm Principal']));

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'user-library-probe',
            backing: BeamSchema::class,
            data: 'Acme\\Data\\UserLibraryProbeData',
            label: '',
        ), ['user']);

        $adapter = app(ParticleResourceRegistryAdapter::class);
        $port = app(ResourceRegistry::class);
        $definition = $adapter->find('user-library-probe');

        $this->assertTrue($adapter->has('user-library-probe'));
        $this->assertSame('user-library-probe', $adapter->get('user-library-probe')->key);
        $this->assertSame('user-library-probe', $definition?->key);
        $this->assertTrue($port->has('user-library-probe'));
        $this->assertSame('user-library-probe', $port->get('user-library-probe')->key);
        $this->assertSame('user-library-probe', $port->find('user-library-probe')?->key);
        $this->assertFalse($this->app->make(ParticleResourceRegistry::class)->hasFramedResource('user-library-probe'));
        $this->assertSame(['user'], $this->app->make(ParticleResourceRegistry::class)->realmsFor('user-library-probe'));

        $route = new RoutingRoute(['GET'], '/frame/resources/{resource}', static fn () => null);
        $request = Request::create('/frame/resources/user-library-probe');
        $route->bind($request);
        $route->setParameter('realm', 'user');
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        $gate = $this->app->make(FrameResourceAccessGate::class);
        $this->assertTrue($gate->allowsResource($definition));

        $route->setParameter('realm', 'tenant');
        $this->assertFalse($gate->allowsResource($definition), 'another realm cannot borrow user membership');

        $this->assertNotContains('user-library-probe', array_map(
            fn ($item): string => $item->key,
            $port->all(),
        ), 'the user-realm access projection does not add a manifest or nav leaf');
    }

    public function test_a_typed_user_resource_is_served_on_its_mounted_user_socket_but_not_another_realm_or_as_guest(): void
    {
        $this->actingAs(new GenericUser(['id' => 17, 'name' => 'User Realm Principal']));
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'user-library-socket-probe',
            backing: BeamSchema::class,
            data: UserLibrarySocketProbeData::class,
            label: '',
        ), ['user']);

        Route::prefix('user/frame/resources')->group(function (): void {
            Route::get('{resource}/schema', [FrameResourceController::class, 'schema'])->defaults('realm', 'user');
        });
        Route::prefix('tenant/frame/resources')->group(function (): void {
            Route::get('{resource}/schema', [FrameResourceController::class, 'schema'])->defaults('realm', 'tenant');
        });

        $this->getJson('user/frame/resources/user-library-socket-probe/schema')->assertOk();
        $this->assertTrue(app(ParticleResourceRegistryAdapter::class)->has('user-library-socket-probe'));
        $this->assertSame('user-library-socket-probe', app(ParticleResourceRegistryAdapter::class)->find('user-library-socket-probe')?->key);
        $this->assertTrue(app(ResourceRegistry::class)->has('user-library-socket-probe'));
        $this->assertSame('user-library-socket-probe', app(ResourceRegistry::class)->find('user-library-socket-probe')?->key);
        $this->getJson('tenant/frame/resources/user-library-socket-probe/schema')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('user/frame/resources/user-library-socket-probe/schema')->assertForbidden();

        $route = Route::getRoutes()->match(Request::create('/user/frame/resources/user-library-socket-probe/schema'));
        $request = Request::create('/user/frame/resources/user-library-socket-probe/schema');
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->assertSame('user', $route->defaults['realm'], 'realm context belongs in the route default, not in the resource segment');
        $this->assertTrue(FrameSocketRoute::serves($request), 'the concrete Frame controller marks this as a resource socket');
    }

    public function test_a_realm_manifest_projects_typed_unframed_resources_without_creating_navigation(): void
    {
        $this->actingAs(new GenericUser(['id' => 18, 'name' => 'User Realm Principal']));
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'user-library-manifest-probe',
            backing: UserLibraryManifestProbeBacking::class,
            data: UserLibrarySocketProbeData::class,
            label: '',
            readOnly: true,
            readPolicy: AuthenticatedReadPolicy::class,
        ), ['user']);

        Route::get('ticket17/frame/user/manifest', FrameManifestController::class)
            ->defaults('realm', 'user');
        Route::get('ticket17/frame/operator/manifest', FrameManifestController::class)
            ->defaults('realm', 'operator');
        Route::get('ticket17/frame/non-manifest-catalog', static fn () => response()->json(
            collect(app(ParticleResourceRegistryAdapter::class)->all())->pluck('key')->all(),
        ));

        $userManifest = $this->getJson('/ticket17/frame/user/manifest')->assertOk()->json();

        $this->assertContains(
            'user-library-manifest-probe',
            collect($userManifest['resources'])->pluck('key')->all(),
            'a user-realm manifest exposes the typed resource to its authorized caller',
        );
        $this->assertArrayNotHasKey('nav', $userManifest, 'a resource declaration does not manufacture a navigation leaf');

        $operatorManifest = $this->getJson('/ticket17/frame/operator/manifest')->assertOk()->json();
        $this->assertNotContains(
            'user-library-manifest-probe',
            collect($operatorManifest['resources'])->pluck('key')->all(),
            'a different realm cannot enumerate the user resource',
        );

        $this->assertNotContains(
            'user-library-manifest-probe',
            $this->getJson('/ticket17/frame/non-manifest-catalog')->assertOk()->json(),
            'an incidental registry enumeration remains presentation-only',
        );

        $this->app['auth']->forgetGuards();
        $guestManifest = $this->getJson('/ticket17/frame/user/manifest')->assertOk()->json();
        $this->assertNotContains(
            'user-library-manifest-probe',
            collect($guestManifest['resources'])->pluck('key')->all(),
            'an authenticated-read declaration is not listed to a guest',
        );
    }
}

class UserLibraryManifestProbeBacking implements ResourceBacking {}

class UserLibrarySocketProbeData extends Data
{
    public function __construct(public string $value) {}
}
