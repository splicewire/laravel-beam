<?php

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Tests\TestCase;

pest()->extend(TestCase::class);

class ReturnsProxyItemData extends Data
{
    public function __construct(public string $id) {}
}

class ReturnsProxyController
{
    public function index(): void {}
}

function returnsProxyEndpoint(): ExtractedEndpointData
{
    return ExtractedEndpointData::fromRoute(new Route(['GET'], 'returns-proxy', [
        'uses' => ReturnsProxyController::class.'@index',
        'controller' => ReturnsProxyController::class.'@index',
    ]));
}

it('projects real proxy metadata into the envelope and item ref', function (bool $many) {
    $endpoint = returnsProxyEndpoint();
    (new BeamRouteProxy($endpoint->route))->returns(ReturnsProxyItemData::class, many: $many);
    // The writer populated its namespace, never the legacy raw keys these tests used to fake.
    expect($endpoint->route->getAction('returns'))->toBeNull();
    $responses = (new ReturnsResponseStrategy(new DocumentationConfig([])))($endpoint);
    expect($responses)->toBeArray()->toHaveCount(1)
        ->and($responses[0]['status'])->toBe(200);
    $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];
    $ref = ['$ref' => '#/$defs/ReturnsProxyItemData'];
    expect($schema['properties']['data'])->toBe($many ? ['type' => 'array', 'items' => $ref] : $ref)
        ->and($schema['$defs']['ReturnsProxyItemData']['properties']['id']['type'])->toBe('string');
    expect(array_keys($schema['properties']))->toBe($many ? ['data', 'limit', 'offset', 'total'] : ['data']);
})->with(['single' => false, 'many' => true]);

it('keeps canonical metadata together when legacy keys conflict', function () {
    $endpoint = returnsProxyEndpoint();
    $endpoint->route->action['returns'] = stdClass::class;
    $endpoint->route->action['returnsMany'] = true;
    (new BeamRouteProxy($endpoint->route))->returns(ReturnsProxyItemData::class);
    (new ReturnsResponseStrategy(new DocumentationConfig([])))($endpoint);
    expect($endpoint->custom['dataResponseSchemas'][0]['schema']['properties']['data'])
        ->toBe(['$ref' => '#/$defs/ReturnsProxyItemData']);
});

it('uses the injected metadata reader for both response slots', function () {
    $endpoint = returnsProxyEndpoint();
    $reader = Mockery::mock(RouteMetadataReader::class);
    $reader->shouldReceive('returns')->once()->with($endpoint->route)->andReturn(ReturnsProxyItemData::class);
    $reader->shouldReceive('returnsMany')->once()->with($endpoint->route)->andReturn(true);
    (new ReturnsResponseStrategy(new DocumentationConfig([]), $reader))($endpoint);
    expect($endpoint->custom['dataResponseSchemas'][0]['schema']['properties']['data'])
        ->toBe(['type' => 'array', 'items' => ['$ref' => '#/$defs/ReturnsProxyItemData']]);
});
