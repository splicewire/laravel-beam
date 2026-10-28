<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Tests\TestCase;

/**
 * The route half of the declared-particle provider's seed (ts-transformer-convergence B2): every DTO a route
 * declares as its response body or stream payload, read through the same two readers the client manifest
 * uses ({@see RouteReturnType::for()} and {@see RouteReturnType::streamsFor()}), so the generated type tree
 * and the client's references cannot disagree about which classes a route names.
 *
 * Each test builds its own {@see Router}, so beam's own registered routes never enter the assertion.
 */
class DeclaredRouteTypesTest extends TestCase
{
    private Router $router;

    private ParticleResourceRegistry $particles;

    private ParticleOperationRegistry $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new Router(new Dispatcher($this->app), $this->app);
        $this->particles = new ParticleResourceRegistry;
        $this->operations = new ParticleOperationRegistry;
    }

    private function returns(): RouteReturnType
    {
        return new RouteReturnType($this->particles, $this->operations);
    }

    private function types(): DeclaredRouteTypes
    {
        return new DeclaredRouteTypes($this->router, $this->returns());
    }

    public function test_an_explicit_beam_returns_wins_over_the_response_attribute(): void
    {
        $this->router->get('/explicit', [RouteSeedFixtureController::class, 'declared'])
            ->name('explicit.show')
            ->beam()->returns(RouteSeedExplicitData::class);
        $this->router->get('/attributed', [RouteSeedFixtureController::class, 'declared']);

        $this->assertSame([
            RouteSeedExplicitData::class => ['route [explicit.show] returns:'],
            RouteSeedOkData::class => ['route [attributed] returns:'],
        ], $this->types()->declared());
    }

    public function test_only_the_lowest_success_body_counts_and_an_error_body_is_excluded(): void
    {
        $this->router->get('/mixed', [RouteSeedFixtureController::class, 'mixedStatuses']);
        $this->router->get('/errors-only', [RouteSeedFixtureController::class, 'errorOnly']);

        $this->assertSame([RouteSeedOkData::class => ['route [mixed] returns:']], $this->types()->declared());
    }

    public function test_streams_give_every_variant_and_a_repeated_event_name_unions(): void
    {
        $this->router->get('/explicit-stream', [RouteSeedFixtureController::class, 'streamed'])
            ->name('explicit.stream')
            ->beam()->streams(['changed' => [RouteSeedExplicitData::class, '\\'.RouteSeedCreatedData::class]]);
        $this->router->get('/attributed-stream', [RouteSeedFixtureController::class, 'streamed']);

        $this->assertSame([
            RouteSeedExplicitData::class => ['route [explicit.stream] streams: [changed]'],
            RouteSeedCreatedData::class => ['route [explicit.stream] streams: [changed]'],
            RouteSeedStartedData::class => ['route [attributed-stream] streams: [status]'],
            RouteSeedFinishedData::class => [
                'route [attributed-stream] streams: [status]',
                'route [attributed-stream] streams: [done]',
            ],
        ], $this->types()->declared());
    }

    public function test_a_closure_a_stale_action_and_a_particle_mount_give_nothing(): void
    {
        $this->particles->register(new ParticleResource(
            key: 'route-seed', backing: Model::class, data: RouteSeedExplicitData::class,
        ));
        $this->operations->register(new ParticleOperation(
            resource: 'route-seed', name: 'refresh', kind: OperationKind::Read,
            handle: fn () => null, ability: false, input: false, output: RouteSeedCreatedData::class,
        ));

        $this->router->get('/closure', fn () => null);
        $this->router->get('/stale', 'Splicewire\\Beam\\Tests\\Codegen\\RouteSeedMissingController@show');
        $this->router->get('/stale-method', [RouteSeedFixtureController::class, 'gone']);
        $resource = $this->router->get('/particle', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'route-seed');
        $operation = $this->router->post('/particle/refresh', [ParticleOperationController::class, 'invoke'])
            ->defaults(ParticleOperationController::RESOURCE, 'route-seed')
            ->defaults(ParticleOperationController::NAME, 'refresh');

        // The particle routes DO resolve a client type — through the particle registries, which are
        // DeclaredParticleTypes' seed, not this one's.
        $this->assertNotNull($this->returns()->for($resource));
        $this->assertNotNull($this->returns()->for($operation));

        $this->assertSame([], $this->types()->declared());
    }

    public function test_an_invokable_controller_agrees_with_the_response_attribute_reader(): void
    {
        $route = $this->router->get('/invokable', RouteSeedInvokableController::class);

        $expected = array_filter([$this->returns()->fromResponseAttribute($route)]);

        // Agreement, not emptiness: whatever the reader resolves for an invokable is what the seed carries,
        // so a reader fix (ticket 07) flows through without touching this class.
        $this->assertSame($expected, array_keys($this->types()->declared()));
    }

    public function test_a_route_registered_after_construction_is_seen(): void
    {
        $types = $this->types();
        $this->assertSame([], $types->declared());

        $this->router->get('/late', [RouteSeedFixtureController::class, 'declared']);

        $this->assertSame([RouteSeedOkData::class => ['route [late] returns:']], $types->declared());
    }
}

class RouteSeedOkData extends Data
{
    public function __construct(public string $id) {}
}

class RouteSeedCreatedData extends Data
{
    public function __construct(public string $id) {}
}

class RouteSeedErrorData extends Data
{
    public function __construct(public string $message) {}
}

class RouteSeedExplicitData extends Data
{
    public function __construct(public string $id) {}
}

class RouteSeedStartedData extends Data
{
    public function __construct(public string $id) {}
}

class RouteSeedFinishedData extends Data
{
    public function __construct(public string $id) {}
}

class RouteSeedFixtureController
{
    #[ResponseFromData(RouteSeedOkData::class)]
    public function declared() {}

    #[ResponseFromData(RouteSeedErrorData::class, status: 422)]
    #[ResponseFromData(RouteSeedCreatedData::class, status: 201)]
    #[ResponseFromData(RouteSeedOkData::class, status: 200)]
    public function mixedStatuses() {}

    #[ResponseFromData(RouteSeedErrorData::class, status: 404)]
    public function errorOnly() {}

    #[StreamsFromData('status', RouteSeedStartedData::class)]
    #[StreamsFromData('status', RouteSeedFinishedData::class)]
    #[StreamsFromData('done', RouteSeedFinishedData::class)]
    public function streamed() {}
}

class RouteSeedInvokableController
{
    #[ResponseFromData(RouteSeedOkData::class)]
    public function __invoke() {}
}
