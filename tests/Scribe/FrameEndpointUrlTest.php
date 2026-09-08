<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Scribe;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Tools\Globals;
use Knuckles\Scribe\Writing\OpenAPISpecWriter;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Scribe\FrameEndpointUrl;
use Splicewire\Beam\Scribe\Strategies\UrlParametersWithoutRowReads;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Scribe::normalizeEndpointUrlUsing(null);
});

it('extracts distinct Frame resource and record parameters before metadata is built', function (string $verb, string $action, string $suffix) {
    $uri = 'custom-frame/resources/{resource}'.$suffix;
    $route = new Route([$verb], $uri, [
        'uses' => FrameResourceController::class.'@'.$action,
        'as' => 'frame.resources.'.$action,
    ]);
    $endpoint = ExtractedEndpointData::fromRoute($route);
    $parameters = (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($endpoint);

    expect($endpoint->uri)->toBe($uri)
        ->and(array_keys($parameters))->toBe($suffix === '' ? ['resource'] : ['resource', 'id'])
        ->and($parameters['resource']['required'])->toBeTrue();
    $output = OutputEndpointData::create([
        'uri' => $endpoint->uri, 'httpMethods' => [$verb], 'urlParameters' => $parameters,
        'metadata' => $endpoint->metadata,
    ]);
    $spec = (new OpenAPISpecWriter(new DocumentationConfig(['openapi' => ['version' => '3.1.0']])))
        ->generateSpecContent([['name' => 'Frame', 'description' => '', 'endpoints' => [$output]]]);
    expect($spec['paths'])->toHaveKey('/'.$uri)
        ->and($spec['paths']['/'.$uri])->toHaveKey(strtolower($verb));
    $emitted = $spec['paths']['/'.$uri]['parameters'];
    expect(array_column($emitted, 'name'))->toBe(array_keys($parameters));
    foreach ($emitted as $parameter) {
        expect($parameter['in'])->toBe('path')->and($parameter['required'])->toBeTrue();
    }
})->with([
    ['GET', 'show', '/records/{id}'],
    ['PUT', 'update', '/records/{id}'],
    ['DELETE', 'destroy', '/records/{id}'],
    ['GET', 'index', ''],
    ['POST', 'store', ''],
]);

it('retains Scribe normalization for unrelated model-bound resource routes', function (string $parameter, string $normalized) {
    $route = new Route(['GET'], 'widgets/{'.$parameter.'}', [
        'uses' => FrameUrlControlController::class.'@show', 'as' => 'widgets.show',
    ]);
    expect(ExtractedEndpointData::fromRoute($route)->uri)->toBe('widgets/{'.$normalized.'}');
})->with([['widget', 'id'], ['widget:slug', 'slug']]);

it('preserves a host normalizer installed before or after Beam', function () {
    $custom = fn (string $uri) => 'host/'.$uri;
    Scribe::normalizeEndpointUrlUsing($custom);
    FrameEndpointUrl::register();
    expect(Globals::$__normalizeEndpointUrlUsing)->toBe($custom);
    $route = new Route(['GET'], 'frame/resources/{resource}', [
        'uses' => FrameResourceController::class.'@index', 'as' => 'frame.resources.index',
    ]);
    expect(ExtractedEndpointData::fromRoute($route)->uri)->toBe('host/frame/resources/{resource}');

    Scribe::normalizeEndpointUrlUsing(null);
    FrameEndpointUrl::register();
    Scribe::normalizeEndpointUrlUsing($custom);
    expect(ExtractedEndpointData::fromRoute($route)->uri)->toBe('host/frame/resources/{resource}');
});

class FrameUrlControlController
{
    public function show(FrameUrlWidget $widget) {}
}

class FrameUrlWidget extends Model {}

it('preserves optional Frame selectors during extraction', function () {
    $route = new Route(['GET'], 'other/resources/{resource}/records/{id?}', [
        'uses' => FrameResourceController::class.'@show', 'as' => 'frame.resources.show',
    ]);
    $endpoint = ExtractedEndpointData::fromRoute($route);
    $parameters = (new UrlParametersWithoutRowReads(new DocumentationConfig([])))($endpoint);
    expect($endpoint->uri)->toBe('other/resources/{resource}/records/{id?}')
        ->and(array_keys($parameters))->toBe(['resource', 'id'])
        ->and($parameters['id']['required'])->toBeFalse();
});
