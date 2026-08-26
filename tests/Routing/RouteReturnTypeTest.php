<?php

namespace Splicewire\Beam\Tests\Routing;

use Illuminate\Routing\Route;
use InvalidArgumentException;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Routing\ClientTypeName;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Tests\TestCase;

class ReturnTypeFixtureData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeAttributeData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeOperationData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeResourceData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeStreamStartedData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeStreamFinishedData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnTypeFixtureController
{
    public function bare() {}

    #[ResponseFromData(ReturnTypeAttributeData::class)]
    public function declared() {}

    /** An error envelope must never become the client's response type. */
    #[ResponseFromData(ReturnTypeFixtureData::class, status: 422)]
    #[ResponseFromData(ReturnTypeAttributeData::class, status: 201)]
    public function mixedStatuses() {}

    #[StreamsFromData('started', ReturnTypeStreamStartedData::class)]
    #[StreamsFromData('finished', ReturnTypeStreamFinishedData::class)]
    public function streamed() {}
}

/**
 * Beam's OWN coverage of {@see RouteReturnType} and {@see ClientTypeName} — api-surface-coherence 46.
 *
 * ## Why this test had to exist
 *
 * 24 moved both classes down into `Splicewire\Beam\Routing\` and left their tests in
 * `splicewire/tower`, because every fixture in those tests is a tower DTO (`CircuitRunData`,
 * `RegistryCatalogData`, …) and beam has no equivalents. So beam shipped two classes with no test of
 * its own that exercised them. That is not cosmetic: during 24 itself beam's suite was green at 812
 * tests while `RouteReturnType::qualify()` fatally referenced a `ClientTypeName` that had not moved
 * yet, and only TOWER's suite caught it. A package whose own suite cannot fail on its own broken
 * class is not covered — it is downstream-covered, which is a different and weaker thing.
 *
 * ## What beam covers here, and what stays tower's
 *
 * Beam proves the **precedence** and the **mechanics** — that the four sources are consulted in
 * declared order, that each one wins over the ones below it, that the stream seam resolves nothing
 * from `for()`, and that `ClientTypeName`'s `class_exists` guard fires. Tower's tests keep proving
 * the same resolver against REAL declarations and real `Splicewire.Tower.Data.*` names, which is
 * genuine consumer-side integration coverage of a kind beam cannot reproduce and should not try to.
 * The two are not duplicates: beam asserts the rule, tower asserts the estate obeys it.
 *
 * Fixtures are throwaway `Data` classes declared above, the pattern the converted strategy tests
 * already use (`ReturnsFixtureData`, `OpFixtureData`) — no `splicewire/tower` class is referenced,
 * which is the point.
 */
class RouteReturnTypeTest extends TestCase
{
    private ParticleResourceRegistry $particles;

    private ParticleOperationRegistry $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->particles = new ParticleResourceRegistry;
        $this->operations = new ParticleOperationRegistry;
    }

    private function resolver(): RouteReturnType
    {
        return new RouteReturnType($this->particles, $this->operations);
    }

    /**
     * `$beam` is written into the `->beam()` action NAMESPACE, not the action root — ticket 15 moved
     * the storage under `beam.*` and `BeamRouteAction` is the only reader that knows so. Spelling it
     * the way the proxy stores it is what makes this a test of the real path.
     *
     * @param  array<string, mixed>  $beam
     * @param  array<string, mixed>  $defaults
     */
    private function route(string $method = 'bare', array $beam = [], array $defaults = [], string $name = 'fixtures.show'): Route
    {
        $uses = ReturnTypeFixtureController::class.'@'.$method;

        $route = new Route(['GET'], 'fixtures', array_filter([
            'uses' => $uses,
            'controller' => $uses,
            BeamRouteProxy::ACTION => $beam,
        ]));

        $route->name($name);
        $route->defaults = array_merge($route->defaults, $defaults);

        return $route;
    }

    /** Register a particle resource whose `data` DTO is source (4). */
    private function registerResource(string $key = 'fixtures'): void
    {
        $this->particles->register(new ParticleResource(
            key: $key,
            backing: 'fixtures',
            data: ReturnTypeResourceData::class,
        ));
    }

    /**
     * Register a particle operation whose `output:` slot is source (3).
     *
     * @param  string|array<string, list<class-string>>|null  $output
     */
    private function registerOperation(string|array|null $output, OperationKind $kind = OperationKind::Read): void
    {
        $this->operations->register(new ParticleOperation(
            resource: 'fixtures',
            name: 'peek',
            kind: $kind,
            model: ReturnTypeFixtureData::class,
            handle: fn () => null,
            output: $output,
        ));
    }

    /** @return array<string, mixed> */
    private function operationDefaults(): array
    {
        return [
            ParticleOperationController::RESOURCE => 'fixtures',
            ParticleOperationController::NAME => 'peek',
        ];
    }

    // ── The four sources, each proven to resolve on its own ───────────────────────────────────────

    public function test_nothing_declared_resolves_to_null(): void
    {
        // A route with no declaration is not an error — it still gets a route-map entry and its hooks
        // stay hand-written. Null is the contract, not a failure.
        $this->assertNull($this->resolver()->for($this->route()));
    }

    public function test_source_one_the_explicit_returns_macro(): void
    {
        $resolved = $this->resolver()->for($this->route(beam: ['returns' => ReturnTypeFixtureData::class]));

        $this->assertSame(ClientTypeName::for(ReturnTypeFixtureData::class), $resolved['type']);
        $this->assertFalse($resolved['many']);
    }

    public function test_the_explicit_macro_carries_its_own_cardinality(): void
    {
        // Only source (1) declares cardinality; every other source is single by construction except a
        // particle index route, which derives it from the route NAME.
        $resolved = $this->resolver()->for($this->route(beam: [
            'returns' => ReturnTypeFixtureData::class,
            'returnsMany' => true,
        ]));

        $this->assertTrue($resolved['many']);
    }

    public function test_source_two_the_response_attribute_on_the_controller_method(): void
    {
        $resolved = $this->resolver()->for($this->route('declared'));

        $this->assertSame(ClientTypeName::for(ReturnTypeAttributeData::class), $resolved['type']);
        $this->assertFalse($resolved['many'], 'The attribute declares a body shape, not a cardinality.');
    }

    public function test_the_response_attribute_selects_the_lowest_2xx_and_never_an_error_envelope(): void
    {
        $resolved = $this->resolver()->for($this->route('mixedStatuses'));

        $this->assertSame(ClientTypeName::for(ReturnTypeAttributeData::class), $resolved['type']);
    }

    public function test_source_three_a_particle_operations_declared_output(): void
    {
        $this->registerOperation(ReturnTypeOperationData::class);

        $resolved = $this->resolver()->for($this->route(defaults: $this->operationDefaults()));

        $this->assertSame(ClientTypeName::for(ReturnTypeOperationData::class), $resolved['type']);
    }

    public function test_an_unregistered_operation_is_a_reportable_absence_not_an_exception(): void
    {
        // The route claims an operation that was never registered. A manifest build walks the whole
        // live route table; one unregistered operation must not kill it.
        $this->assertNull($this->resolver()->for($this->route(defaults: $this->operationDefaults())));
    }

    public function test_source_four_a_particle_resources_data_dto(): void
    {
        $this->registerResource();

        $resolved = $this->resolver()->for($this->route(defaults: [ParticleController::RESOURCE => 'fixtures']));

        $this->assertSame(ClientTypeName::for(ReturnTypeResourceData::class), $resolved['type']);
        $this->assertFalse($resolved['many']);
    }

    public function test_a_particle_index_route_derives_its_cardinality_from_the_route_name(): void
    {
        $this->registerResource();

        $resolved = $this->resolver()->for($this->route(
            defaults: [ParticleController::RESOURCE => 'fixtures'],
            name: 'fixtures.index',
        ));

        $this->assertTrue($resolved['many']);
    }

    public function test_a_resource_with_no_data_dto_derives_nothing(): void
    {
        // A resource carrying only a `project` projector is NOT auto-derived — the projector's return
        // may be a package-owned wire type, so that call stays explicit.
        $this->particles->register(new ParticleResource(key: 'projected', backing: 'projected'));

        $this->assertNull($this->resolver()->for(
            $this->route(defaults: [ParticleController::RESOURCE => 'projected']),
        ));
    }

    // ── Precedence: the thing beam actually owns ─────────────────────────────────────────────────

    public function test_the_explicit_macro_beats_the_method_attribute(): void
    {
        $resolved = $this->resolver()->for($this->route('declared', ['returns' => ReturnTypeFixtureData::class]));

        $this->assertSame(ClientTypeName::for(ReturnTypeFixtureData::class), $resolved['type']);
    }

    public function test_the_method_attribute_beats_a_particle_operation(): void
    {
        $this->registerOperation(ReturnTypeOperationData::class);

        $resolved = $this->resolver()->for($this->route('declared', defaults: $this->operationDefaults()));

        $this->assertSame(ClientTypeName::for(ReturnTypeAttributeData::class), $resolved['type']);
    }

    public function test_a_particle_operation_beats_the_resource_it_hangs_off(): void
    {
        $this->registerResource();
        $this->registerOperation(ReturnTypeOperationData::class);

        $resolved = $this->resolver()->for($this->route(defaults: array_merge(
            $this->operationDefaults(),
            [ParticleController::RESOURCE => 'fixtures'],
        )));

        $this->assertSame(ClientTypeName::for(ReturnTypeOperationData::class), $resolved['type']);
    }

    public function test_the_whole_precedence_ladder_in_one_route(): void
    {
        // All four sources present at once. The order is the contract, so assert it end to end rather
        // than only pairwise — a reordering that keeps every pair passing is possible, and this is the
        // assertion that catches it.
        $this->registerResource();
        $this->registerOperation(ReturnTypeOperationData::class);

        $route = $this->route('declared', ['returns' => ReturnTypeFixtureData::class], array_merge(
            $this->operationDefaults(),
            [ParticleController::RESOURCE => 'fixtures'],
        ));

        $this->assertSame(ClientTypeName::for(ReturnTypeFixtureData::class), $this->resolver()->for($route)['type']);
    }

    // ── The stream seam ──────────────────────────────────────────────────────────────────────────

    public function test_a_stream_operation_resolves_nothing_from_for(): void
    {
        // A stream has no single response type; its shape belongs under `streams`, not `returns`.
        $this->registerOperation(
            ['started' => [ReturnTypeStreamStartedData::class]],
            OperationKind::Stream,
        );

        $this->assertNull($this->resolver()->for($this->route(defaults: $this->operationDefaults())));
    }

    public function test_streams_resolve_from_the_method_attribute_qualified(): void
    {
        $streams = $this->resolver()->streamsFor($this->route('streamed'));

        $this->assertSame([
            'started' => [ClientTypeName::for(ReturnTypeStreamStartedData::class)],
            'finished' => [ClientTypeName::for(ReturnTypeStreamFinishedData::class)],
        ], $streams);
    }

    public function test_an_explicit_streams_macro_beats_the_method_attribute(): void
    {
        $streams = $this->resolver()->streamsFor($this->route('streamed', [
            'streams' => ['started' => [ReturnTypeStreamFinishedData::class]],
        ]));

        $this->assertSame(
            ['started' => [ClientTypeName::for(ReturnTypeStreamFinishedData::class)]],
            $streams,
        );
    }

    public function test_streams_derive_from_a_stream_operations_output_map(): void
    {
        $this->registerOperation(
            ['progress' => [ReturnTypeStreamStartedData::class, ReturnTypeStreamFinishedData::class]],
            OperationKind::Stream,
        );

        $this->assertSame(
            ['progress' => [
                ClientTypeName::for(ReturnTypeStreamStartedData::class),
                ClientTypeName::for(ReturnTypeStreamFinishedData::class),
            ]],
            $this->resolver()->streamsFor($this->route(defaults: $this->operationDefaults())),
        );
    }

    public function test_a_non_streaming_route_streams_nothing(): void
    {
        $this->assertNull($this->resolver()->streamsFor($this->route()));
    }

    // ── The per-build reflection cache ───────────────────────────────────────────────────────────

    public function test_an_action_is_reflected_once_however_many_routes_name_it(): void
    {
        // A manifest build revisits the same action across contracts; reflection is the expensive step.
        $resolver = $this->resolver();

        $resolver->for($this->route('declared'));
        $resolver->for($this->route('declared', name: 'fixtures.other'));
        $resolver->streamsFor($this->route('declared'));

        $this->assertSame(1, $resolver->reflectionCount());
    }

    public function test_an_unreflectable_action_yields_nothing_rather_than_raising(): void
    {
        // A closure route, and a stale action naming a class that no longer exists. Both appear in a
        // live route table and neither may abort a manifest build.
        $closure = new Route(['GET'], 'closure', ['uses' => fn () => null]);
        $stale = new Route(['GET'], 'stale', [
            'uses' => 'App\\Gone\\Controller@index',
            'controller' => 'App\\Gone\\Controller@index',
        ]);

        $resolver = $this->resolver();

        $this->assertNull($resolver->for($closure));
        $this->assertNull($resolver->for($stale));
        $this->assertNull($resolver->streamsFor($stale));
    }

    // ── ClientTypeName ───────────────────────────────────────────────────────────────────────────

    public function test_a_class_name_projects_to_its_native_namespace_with_dots(): void
    {
        $this->assertSame(
            'Splicewire.Beam.Tests.Routing.ReturnTypeFixtureData',
            ClientTypeName::for(ReturnTypeFixtureData::class),
        );
    }

    public function test_a_leading_backslash_is_stripped_before_projection(): void
    {
        $this->assertSame(
            ClientTypeName::for(ReturnTypeFixtureData::class),
            ClientTypeName::for('\\'.ReturnTypeFixtureData::class),
        );
    }

    public function test_an_unimported_class_string_is_refused_at_the_source(): void
    {
        // THE TRAP the docblock describes: `->returns(SomeClass::class)` in a route file with no
        // matching `use` import silently resolves to the bare string "SomeClass" — PHP does not error
        // on `::class` the way it would on a real instantiation. Unguarded, that garbage string used
        // to fall through to a plausible-but-wrong TS name, hiding an import bug behind a confusing
        // TypeScript compile error much later. It has to fail here, where the cause is.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no such class');

        ClientTypeName::for('ReturnTypeFixtureData');
    }

    public function test_the_unimported_guard_fires_through_the_resolver_too(): void
    {
        // Not merely a unit-level guard on the helper: the degraded string arrives via a route macro,
        // so the failure must survive the path a real `->returns()` takes.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no such class');

        $this->resolver()->for($this->route(beam: ['returns' => 'ReturnTypeFixtureData']));
    }
}
