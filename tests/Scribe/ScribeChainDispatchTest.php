<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleListParameterStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationParameterStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Tests\Fixtures\NarrowFixtureGenerator;
use Splicewire\Beam\Tests\Fixtures\RefusingFixtureGenerator;
use Splicewire\Beam\Tests\TestCase;

/**
 * A class the narrow fixture generator ACCEPTS — `NarrowFixtureGenerator` matches on the substring
 * `WidgetGateData`, so the name is the fixture's whole opt-in mechanism.
 */
class ChainWidgetGateDataInput extends Data
{
    public function __construct(
        public string $id = '',
        public string $title = '',
    ) {}
}

/** A class the narrow generator REFUSES, so it must fall THROUGH to the next chain member. */
class ChainOrdinaryData extends Data
{
    public function __construct(
        public string $id = '',
        public int $total = 0,
    ) {}
}

class ChainListModel extends Model
{
    protected $table = 'catalogs';
}

class ChainListFilterData extends Data
{
    public function __construct(
        #[Description('The catalog\'s display name.')]
        #[Filterable(Exact::class)]
        public string|Optional|null $name = null,
    ) {}
}

class ChainListQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ChainListModel::query();
    }
}

/**
 * Beam's Scribe strategies dispatch over the host's configured generator LIST.
 *
 * These six sites used to hand-build `new JsonSchemaGenerator(config('data-schemas', []))`, which is
 * correct on CONFIG and blind on DISPATCH: `data-schemas.generators` is a list, and the rule "the
 * first member whose `canGenerate()` accepts this class" lives only inside `ChainedGenerator`. At
 * `~/Herd/thingsontv` — the estate's one multi-generator host, configured
 * `[BlockJsonSchemaGenerator, JsonSchemaGenerator]` — that ran the PLAIN generator over a class the
 * narrow member owns and emitted a downgraded document behind a successful extraction.
 *
 * The tests take that shape: a narrow generator configured FIRST wins for a class it accepts, and a
 * class it refuses falls THROUGH to the next member rather than being handed `generators[0]` by
 * position.
 *
 * The refusal half is the more expensive one. `ChainedGenerator::generate()` THROWS where the
 * hand-built generator generated regardless, and a throw raised inside a Scribe strategy is not
 * loud: Scribe catches per-route, prints only under `-v`, and carries on — the endpoint simply
 * VANISHES from the spec. This package already carries one 30-endpoint scar from exactly that
 * mechanism (see `ParticleListParameterStrategy::__invoke()`), so every site guards with
 * `canGenerate()` and degrades to its own existing "nothing to contribute" answer instead.
 */
class ScribeChainDispatchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // The list strategy is the only one here that reads data-filters declarations; beam's base
        // harness does not boot that provider.
        return [...parent::getPackageProviders($app), DataFiltersServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ParticleResourceRegistry::class);
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    /** The thingsontv shape: narrow FIRST, ordinary generator behind it. */
    private function narrowFirst(): void
    {
        config()->set('data-schemas.generators', [NarrowFixtureGenerator::class, JsonSchemaGenerator::class]);
    }

    /** A host whose configured chain has no member willing to build anything. */
    private function refusesEverything(): void
    {
        config()->set('data-schemas.generators', [RefusingFixtureGenerator::class]);
    }

    private function registerResource(?string $data, string|false|null $input, bool $filterable = false): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'catalogs',
            backing: ChainListModel::class,
            data: $data,
            input: $input,
            filterable: $filterable,
        ));
    }

    private function registerOp(?string $input, ?string $output, OperationKind $kind = OperationKind::Write): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'catalogs',
            name: 'recalculate',
            kind: $kind,
            model: ChainListModel::class,
            handle: fn () => null,
            input: $input,
            output: $output,
        ));
    }

    private function resourceEndpoint(string $method, string $verb = 'POST'): ExtractedEndpointData
    {
        $route = (new Route([$verb], 'catalogs', [
            'uses' => ParticleController::class.'@'.$method,
            'controller' => ParticleController::class.'@'.$method,
        ]))->defaults(ParticleController::RESOURCE, 'catalogs');

        return ExtractedEndpointData::fromRoute($route);
    }

    private function operationEndpoint(string $verb = 'POST'): ExtractedEndpointData
    {
        $route = (new Route([$verb], 'catalogs/op/recalculate', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'catalogs')
            ->defaults(ParticleOperationController::NAME, 'recalculate');

        return ExtractedEndpointData::fromRoute($route);
    }

    // ── ParticleRequestStrategy ────────────────────────────────────────────────────────────────

    public function test_the_request_strategy_dispatches_to_the_narrow_generator_configured_first(): void
    {
        $this->narrowFirst();
        $this->registerResource(data: null, input: ChainWidgetGateDataInput::class);

        $endpoint = $this->resourceEndpoint('store');
        $parameters = (new ParticleRequestStrategy(new DocumentationConfig([])))($endpoint);

        // The marker is the whole point: `JsonSchemaGenerator` never emits a `narrowMarker` property,
        // so this key can only have come from dispatch picking the first accepting member.
        $this->assertSame(NarrowFixtureGenerator::SCHEMA, $endpoint->custom['dataRequestSchema']);
        $this->assertArrayHasKey('narrowMarker', $parameters);
    }

    public function test_a_class_the_narrow_generator_refuses_falls_through_rather_than_taking_generators_zero(): void
    {
        $this->narrowFirst();
        $this->registerResource(data: null, input: ChainOrdinaryData::class);

        $endpoint = $this->resourceEndpoint('store');
        $parameters = (new ParticleRequestStrategy(new DocumentationConfig([])))($endpoint);

        // Fell through to JsonSchemaGenerator — the real properties, and no marker.
        $this->assertArrayNotHasKey('narrowMarker', $endpoint->custom['dataRequestSchema']['properties']);
        $this->assertArrayHasKey('id', $parameters);
        $this->assertArrayHasKey('total', $parameters);
    }

    public function test_the_request_strategy_documents_a_bodyless_endpoint_rather_than_letting_a_refusal_throw(): void
    {
        $this->refusesEverything();
        $this->registerResource(data: null, input: ChainOrdinaryData::class);

        $endpoint = $this->resourceEndpoint('store');
        $parameters = (new ParticleRequestStrategy(new DocumentationConfig([])))($endpoint);

        // No throw — an unguarded `ChainedGenerator::generate()` here would be swallowed by Scribe and
        // the endpoint would be absent from the spec entirely.
        $this->assertSame([], $parameters);
        $this->assertArrayNotHasKey('dataRequestSchema', $endpoint->custom);
    }

    // ── ParticleResponseStrategy (resource arm) ────────────────────────────────────────────────

    public function test_the_response_strategy_dispatches_to_the_narrow_generator_for_a_class_it_accepts(): void
    {
        $this->narrowFirst();
        $this->registerResource(data: ChainWidgetGateDataInput::class, input: null);

        $endpoint = $this->resourceEndpoint('show', 'GET');
        (new ParticleResponseStrategy(new DocumentationConfig([])))($endpoint);

        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        // The envelope `$ref`-hoists the item schema into `$defs`, so the marker is read there.
        $this->assertSame(['$ref' => '#/$defs/ChainWidgetGateDataInput'], $schema['properties']['data']);
        $this->assertSame(NarrowFixtureGenerator::SCHEMA, $schema['$defs']['ChainWidgetGateDataInput']);
    }

    public function test_the_response_strategy_keeps_the_endpoint_when_the_chain_refuses_its_data_class(): void
    {
        $this->refusesEverything();
        $this->registerResource(data: ChainOrdinaryData::class, input: null);

        $endpoint = $this->resourceEndpoint('show', 'GET');
        $responses = (new ParticleResponseStrategy(new DocumentationConfig([])))($endpoint);

        // The same `[]` a declared-but-unusable `data:` slot already took: undescribed on the response
        // axis, still present in the spec.
        $this->assertSame([], $responses);
        $this->assertArrayNotHasKey('dataResponseSchemas', $endpoint->custom);
    }

    // ── ParticleResponseStrategy (operation arm) ───────────────────────────────────────────────

    public function test_the_operation_output_slot_dispatches_over_the_chain(): void
    {
        $this->narrowFirst();
        $this->registerOp(input: null, output: ChainWidgetGateDataInput::class);

        $endpoint = $this->operationEndpoint();
        (new ParticleResponseStrategy(new DocumentationConfig([])))($endpoint);

        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        // The envelope `$ref`-hoists the item schema into `$defs`, so the marker is read there.
        $this->assertSame(['$ref' => '#/$defs/ChainWidgetGateDataInput'], $schema['properties']['data']);
        $this->assertSame(NarrowFixtureGenerator::SCHEMA, $schema['$defs']['ChainWidgetGateDataInput']);
    }

    public function test_a_refused_operation_output_degrades_to_the_generic_envelope(): void
    {
        $this->refusesEverything();
        $this->registerOp(input: null, output: ChainOrdinaryData::class);

        $endpoint = $this->operationEndpoint();
        $responses = (new ParticleResponseStrategy(new DocumentationConfig([])))($endpoint);

        // Exactly what an undeclared slot already produces — an undescribed 200, not a missing route.
        $this->assertCount(1, $responses);
        $this->assertSame(['type' => 'object'], $endpoint->custom['dataResponseSchemas'][0]['schema']);
    }

    // ── ReturnsResponseStrategy ────────────────────────────────────────────────────────────────

    public function test_the_returns_strategy_dispatches_over_the_chain(): void
    {
        $this->narrowFirst();

        $route = new Route(['GET'], 'fixtures', [
            'uses' => ParticleController::class.'@index',
            'controller' => ParticleController::class.'@index',
            'returns' => ChainWidgetGateDataInput::class,
        ]);
        $endpoint = ExtractedEndpointData::fromRoute($route);

        (new ReturnsResponseStrategy(new DocumentationConfig([])))($endpoint);

        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        // The envelope `$ref`-hoists the item schema into `$defs`, so the marker is read there.
        $this->assertSame(['$ref' => '#/$defs/ChainWidgetGateDataInput'], $schema['properties']['data']);
        $this->assertSame(NarrowFixtureGenerator::SCHEMA, $schema['$defs']['ChainWidgetGateDataInput']);
    }

    public function test_the_returns_strategy_defers_when_the_chain_refuses_so_a_later_strategy_can_answer(): void
    {
        $this->refusesEverything();

        $route = new Route(['GET'], 'fixtures', [
            'uses' => ParticleController::class.'@index',
            'controller' => ParticleController::class.'@index',
            'returns' => ChainOrdinaryData::class,
        ]);
        $endpoint = ExtractedEndpointData::fromRoute($route);

        // `null`, not `[]`: this strategy is registered AHEAD of the particle strategy so an explicit
        // `->returns()` wins, and a generator refusal must not be allowed to outrank a declaration
        // that another strategy could still document.
        $this->assertNull((new ReturnsResponseStrategy(new DocumentationConfig([])))($endpoint));
        $this->assertArrayNotHasKey('dataResponseSchemas', $endpoint->custom);
    }

    // ── ParticleOperationParameterStrategy (GET mount) ─────────────────────────────────────────

    public function test_a_get_operations_declared_input_dispatches_over_the_chain(): void
    {
        $this->narrowFirst();
        $this->registerOp(input: ChainWidgetGateDataInput::class, output: null, kind: OperationKind::Read);

        $parameters = (new ParticleOperationParameterStrategy(new DocumentationConfig([])))(
            $this->operationEndpoint('GET'),
        );

        $this->assertArrayHasKey('narrowMarker', $parameters);
    }

    public function test_a_get_operation_keeps_its_framework_parameters_when_the_chain_refuses_its_input(): void
    {
        $this->refusesEverything();
        $this->registerOp(input: ChainOrdinaryData::class, output: null, kind: OperationKind::Read);

        $parameters = (new ParticleOperationParameterStrategy(new DocumentationConfig([])))(
            $this->operationEndpoint('GET'),
        );

        // The declared axis is gone; the framework axis is not, and nothing threw.
        $this->assertArrayNotHasKey('id', $parameters);
        $this->assertArrayNotHasKey('total', $parameters);
    }

    // ── ParticleListParameterStrategy ──────────────────────────────────────────────────────────

    private function registerFilterResource(): void
    {
        DataFilter::resource('catalogs', [
            'data' => ChainListFilterData::class,
            'query' => ChainListQuery::class,
            'model' => ChainListModel::class,
        ]);

        $this->registerResource(data: ChainListFilterData::class, input: null, filterable: true);
    }

    public function test_a_facet_class_the_narrow_generator_refuses_falls_through_and_keeps_its_prose(): void
    {
        $this->narrowFirst();
        $this->registerFilterResource();

        $parameters = (new ParticleListParameterStrategy(new DocumentationConfig([])))(
            $this->resourceEndpoint('index', 'GET'),
        );

        $key = config('query-builder.parameters.filter', 'filter').'[name]';

        // Fell through to JsonSchemaGenerator, so the `#[Description]` survived. Handing this class to
        // `generators[0]` by position would have produced the marker schema, whose properties join to
        // nothing — the facet would publish untyped and undescribed.
        $this->assertArrayHasKey($key, $parameters);
        $this->assertStringContainsString('display name', $parameters[$key]['description']);
    }

    public function test_a_refused_facet_class_publishes_its_filter_names_untyped_rather_than_vanishing(): void
    {
        $this->refusesEverything();
        $this->registerFilterResource();

        $parameters = (new ParticleListParameterStrategy(new DocumentationConfig([])))(
            $this->resourceEndpoint('index', 'GET'),
        );

        $key = config('query-builder.parameters.filter', 'filter').'[name]';

        // The SET still comes from the query object; only the schema-derived join is empty. Before the
        // guard this was a `RuntimeException` Scribe swallowed, taking the whole index endpoint with it
        // — the same mechanism that once cost 30 endpoints at the flagship.
        $this->assertArrayHasKey($key, $parameters);
        $this->assertSame('string', $parameters[$key]['type']);
    }
}
