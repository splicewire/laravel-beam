<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Tests\TestCase;

class ReturnsFixtureData extends Data
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}

class ReturnsFixtureController
{
    public function index() {}
}

/**
 * Issue client-sdk-regen #02 — the `->returns()` → OpenAPI response bridge.
 *
 * Proves the strategy turns a route's `->returns(X::class)` / `returnsMany` annotation into the stashed
 * `{ data: X }` (single) / `{ data: [X], … }` (list) envelope schema the document assembler ($ref-hoists),
 * and defers (null) on a route without the annotation so it composes with the other response strategies.
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24, together with `RouteReturnType` — the
 * declaration it reads is a beam `->beam()->returns()` macro (ticket 15), so the reader belongs beside it.
 */
class ReturnsResponseStrategyTest extends TestCase
{
    /** @param  array<string, mixed>  $action */
    private function endpoint(array $action): ExtractedEndpointData
    {
        $route = new Route(['GET'], 'fixtures', array_merge(
            ['uses' => ReturnsFixtureController::class.'@index', 'controller' => ReturnsFixtureController::class.'@index'],
            $action,
        ));

        return ExtractedEndpointData::fromRoute($route);
    }

    private function strategy(): ReturnsResponseStrategy
    {
        return new ReturnsResponseStrategy(new DocumentationConfig([]));
    }

    public function test_a_returns_route_emits_a_single_item_data_envelope_with_a_ref(): void
    {
        $endpoint = $this->endpoint(['returns' => ReturnsFixtureData::class]);

        $responses = $this->strategy()($endpoint);

        // Scribe-facing response is a populated 200.
        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);

        // The stash the document assembler ($ref-hoists) reads.
        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['$ref' => '#/$defs/ReturnsFixtureData'], $schema['properties']['data']);
        $this->assertArrayHasKey('ReturnsFixtureData', $schema['$defs']);
        $this->assertArrayHasKey('id', $schema['$defs']['ReturnsFixtureData']['properties']);
        $this->assertArrayHasKey('title', $schema['$defs']['ReturnsFixtureData']['properties']);
    }

    public function test_a_returns_many_route_emits_the_paginated_list_envelope(): void
    {
        $endpoint = $this->endpoint(['returns' => ReturnsFixtureData::class, 'returnsMany' => true]);

        $this->strategy()($endpoint);

        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        $this->assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/$defs/ReturnsFixtureData']],
            $schema['properties']['data'],
        );
        foreach (['data', 'limit', 'offset', 'total'] as $key) {
            $this->assertArrayHasKey($key, $schema['properties']);
        }
        $this->assertArrayHasKey('ReturnsFixtureData', $schema['$defs']);
    }

    public function test_a_route_without_a_returns_annotation_defers_so_other_strategies_compose(): void
    {
        $endpoint = $this->endpoint([]);

        $this->assertNull($this->strategy()($endpoint));
        $this->assertNull($endpoint->custom['dataResponseSchemas'] ?? null);
    }
}
