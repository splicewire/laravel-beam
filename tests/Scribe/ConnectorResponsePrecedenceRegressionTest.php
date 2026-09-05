<?php

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Tests\TestCase;

pest()->extend(TestCase::class);

class ConnectorDeclaredResponseFixture extends Data
{
    public function __construct(public string $declared) {}
}

class ConnectorDerivedResponseFixture extends Data
{
    public function __construct(public int $derived) {}
}

class ConnectorResponseControllerFixture
{
    #[ResponseFromData(ConnectorDeclaredResponseFixture::class, status: 201)]
    public function create(): void {}

    #[ResponseFromData(ConnectorDeclaredResponseFixture::class, status: 422)]
    public function errorOnly(): void {}
}

function connectorResponseEndpoint(string $method): ExtractedEndpointData
{
    app()->singleton(ParticleOperationRegistry::class);
    app(ParticleOperationRegistry::class)->register(new ParticleOperation(
        resource: 'response-fixture', name: 'create',
        kind: OperationKind::Write, model: 'UnusedModel',
        handle: fn () => null, output: ConnectorDerivedResponseFixture::class,
    ));

    return ExtractedEndpointData::fromRoute((new Route(['POST'], 'response-fixture/create', [
        'uses' => ConnectorResponseControllerFixture::class.'@'.$method,
        'controller' => ConnectorResponseControllerFixture::class.'@'.$method,
    ]))->defaults(ParticleOperationController::RESOURCE, 'response-fixture')
        ->defaults(ParticleOperationController::NAME, 'create'));
}

it('preserves an attribute declared 201 through particle response extraction', function () {
    $endpoint = connectorResponseEndpoint('create');
    $config = new DocumentationConfig([]);
    (new UseDataResponse($config))($endpoint);
    $declared = $endpoint->custom['dataResponseSchemas'];
    expect($declared[0]['status'])->toBe(201)
        ->and($declared[0]['schema']['properties'])->toHaveKey('declared');
    expect((new ParticleResponseStrategy($config))($endpoint))->toBeNull()
        ->and($endpoint->custom['dataResponseSchemas'])->toBe($declared);
});

it('preserves the macro override through particle response extraction', function () {
    $endpoint = connectorResponseEndpoint('create');
    $endpoint->route->setAction(array_merge($endpoint->route->getAction(), [
        'returns' => ConnectorDeclaredResponseFixture::class,
    ]));
    $config = new DocumentationConfig([]);
    (new ReturnsResponseStrategy($config))($endpoint);
    $declared = $endpoint->custom['dataResponseSchemas'];
    expect((new ParticleResponseStrategy($config))($endpoint))->toBeNull()
        ->and($endpoint->custom['dataResponseSchemas'])->toBe($declared);
});

it('retains declared errors while deriving a particle success', function () {
    $endpoint = connectorResponseEndpoint('errorOnly');
    $config = new DocumentationConfig([]);
    (new UseDataResponse($config))($endpoint);
    (new ParticleResponseStrategy($config))($endpoint);
    $schemas = array_column($endpoint->custom['dataResponseSchemas'], 'schema', 'status');
    expect($schemas)->toHaveKeys([200, 422])
        ->and($schemas[422]['properties'])->toHaveKey('declared')
        ->and($schemas[200]['properties']['data'])->toBe(['$ref' => '#/$defs/ConnectorDerivedResponseFixture']);
});
