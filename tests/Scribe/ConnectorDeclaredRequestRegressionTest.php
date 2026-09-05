<?php

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\BaseGenerator;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationParameterStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Tests\TestCase;

pest()->extend(TestCase::class);

enum ConnectorRequestStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

class ConnectorDeclaredInput extends Data
{
    public function __construct(
        public ConnectorRequestStatus $status = ConnectorRequestStatus::Open,
        public int $limit = 10,
    ) {}
}

class ConnectorExplicitInput extends Data
{
    public function __construct(public string $message) {}
}

class ConnectorExplicitController
{
    #[RequestFromData(ConnectorExplicitInput::class)]
    public function invoke(): void {}
}

function connectorRequestOperation(string $verb, string $controller = ParticleOperationController::class): array
{
    app()->singleton(ParticleOperationRegistry::class);
    app(ParticleOperationRegistry::class)->register(new ParticleOperation(
        resource: 'connector-regression', name: 'inspect',
        kind: $verb === 'GET' ? OperationKind::Read : OperationKind::Write,
        model: 'stdClass', handle: fn () => null, input: ConnectorDeclaredInput::class,
    ));
    $route = (new Route([$verb], 'connector-regression/{id}/inspect', [
        'uses' => $controller.'@invoke', 'controller' => $controller.'@invoke',
    ]))->defaults(ParticleOperationController::RESOURCE, 'connector-regression')
        ->defaults(ParticleOperationController::NAME, 'inspect');
    $extracted = ExtractedEndpointData::fromRoute($route);
    $config = new DocumentationConfig([]);
    $query = (new ParticleOperationParameterStrategy($config))($extracted) ?? [];
    $body = (new UseDataRequest($config))($extracted) ?? [];
    $body = array_merge($body, (new ParticleRequestStrategy($config))($extracted) ?? []);
    $endpoint = OutputEndpointData::create([
        'httpMethods' => $extracted->httpMethods, 'uri' => $extracted->uri,
        'metadata' => ['title' => 'Inspect', 'description' => '', 'groupName' => 'Regression'],
        'queryParameters' => $query, 'bodyParameters' => $body, 'custom' => $extracted->custom,
    ]);
    $groups = [['name' => 'Regression', 'description' => '', 'endpoints' => [$endpoint]]];
    $hook = new DataSchemaGenerator($config);

    return [
        $hook->pathItem((new BaseGenerator($config))->pathItem([], $groups, $endpoint), $groups, $endpoint),
        $hook->root([], $groups),
    ];
}

it('publishes GET operation enums as scalars with their declared values', function () {
    [$operation] = connectorRequestOperation('GET');
    $parameters = array_column($operation['parameters'], null, 'name');
    expect($parameters['status']['schema']['type'])->toBe('string');
    expect($parameters['status']['schema']['enum'])->toBe(['open', 'closed']);
    expect($operation)->not->toHaveKey('requestBody');
});

it('preserves GET operation defaults through Scribes parameter DTO', function () {
    [$operation] = connectorRequestOperation('GET');
    $parameters = array_column($operation['parameters'], null, 'name');
    expect($parameters['limit']['schema']['default'] ?? null)->toBe(10);
    expect($parameters['status']['schema']['default'] ?? null)->toBe('open');
});

it('honours explicit request attributes ahead of operation stamps', function () {
    [$operation] = connectorRequestOperation('POST', ConnectorExplicitController::class);
    $schema = $operation['requestBody']['content']['application/json']['schema'];
    expect(array_keys($schema['properties']))->toBe(['message']);
    expect($schema['required'])->toBe(['message']);
});

it('preserves inline body properties and resolvable nested component references', function () {
    [$operation, $root] = connectorRequestOperation('POST');
    $schema = $operation['requestBody']['content']['application/json']['schema'];
    expect($schema['properties']['limit']['default'])->toBe(10);
    $ref = $schema['properties']['status']['$ref'];
    expect($ref)->toStartWith('#/components/schemas/');
    $definition = $root['components']['schemas'][substr($ref, strlen('#/components/schemas/'))];
    expect($definition['enum'])->toBe(['open', 'closed']);
});
