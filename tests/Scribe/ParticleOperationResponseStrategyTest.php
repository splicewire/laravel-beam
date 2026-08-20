<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Tests\TestCase;

class OpFixtureData extends Data
{
    public function __construct(
        public string $id,
        public int $total,
    ) {}
}

/**
 * particle-doctrine-convergence ticket 04 — the OpenAPI half of "one declaration, read by every consumer".
 *
 * The TypeScript manifest resolves an operation route from its declared `output:` slot, so the OpenAPI spec
 * must resolve the SAME endpoint from the SAME declaration. Before the slot existed this strategy could only
 * emit an untyped object envelope for every operation, which is precisely the disagreement the ticket closes:
 * one endpoint described two ways.
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24. The strategy's only tower imports were the
 * three empty `Tower\Particle\*` subclasses it resolved out of the container — the circular anchor that ticket
 * broke — so reading beam's own registry FQN here is the whole move, not a workaround.
 */
class ParticleOperationResponseStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The strategy resolves the registry out of the container, and the host provider normally binds it as
        // a singleton — without that, a `register()` here and the strategy's read would land on two different
        // instances, so the binding is part of the seam under test.
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    private function endpoint(): ExtractedEndpointData
    {
        $route = (new Route(['POST'], 'catalogs/{id}/op/recalculate', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'catalogs')
            ->defaults(ParticleOperationController::NAME, 'recalculate');

        return ExtractedEndpointData::fromRoute($route);
    }

    private function registerOp(string|array|null $output, OperationKind $kind = OperationKind::Write): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'catalogs',
            name: 'recalculate',
            kind: $kind,
            model: 'App\\Models\\Catalog',
            handle: fn () => null,
            output: $output,
        ));
    }

    private function strategy(): ParticleResponseStrategy
    {
        return new ParticleResponseStrategy(new DocumentationConfig([]));
    }

    public function test_an_operation_declaring_an_output_slot_documents_that_shape(): void
    {
        $this->registerOp(OpFixtureData::class);
        $endpoint = $this->endpoint();

        $responses = $this->strategy()($endpoint);

        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);

        $schema = $endpoint->custom['dataResponseSchemas'][0]['schema'];

        // A single resolved payload → the item envelope, matching what the TS manifest types the hook as.
        $this->assertSame(['$ref' => '#/$defs/OpFixtureData'], $schema['properties']['data']);
        $this->assertArrayHasKey('id', $schema['$defs']['OpFixtureData']['properties']);
        $this->assertArrayHasKey('total', $schema['$defs']['OpFixtureData']['properties']);
    }

    public function test_an_operation_declaring_no_output_slot_still_documents_a_generic_envelope(): void
    {
        $this->registerOp(null);
        $endpoint = $this->endpoint();

        $this->assertCount(1, $this->strategy()($endpoint));
        $this->assertSame(['type' => 'object'], $endpoint->custom['dataResponseSchemas'][0]['schema']);
    }

    public function test_a_stream_operation_documents_its_event_union_not_a_generic_object(): void
    {
        // A stream emits a sequence of typed events under distinct wire names; there is no single response
        // body — so it stashes the event map for the x-sse-events projection instead
        // (particle-doctrine-followups 14; before, this endpoint fell to `{type: object}` and the SSE
        // surface was a silent hole in the spec). Picking one event DTO as "the" response would be a lie.
        $this->registerOp(['run_status' => [OpFixtureData::class]], OperationKind::Stream);
        $endpoint = $this->endpoint();

        $responses = $this->strategy()($endpoint);

        $this->assertCount(1, $responses);
        $this->assertStringContainsString('text/event-stream', $responses[0]['description']);
        $this->assertStringContainsString("event: run_status\ndata: {", $responses[0]['content']);

        $stash = $endpoint->custom['dataStreamSchemas'];

        $this->assertArrayNotHasKey('dataResponseSchemas', $endpoint->custom);
        $this->assertSame(['run_status'], array_column($stash, 'event'));
        $this->assertSame('OpFixtureData', $stash[0]['schemas'][0]['title']);
    }

    public function test_an_operation_route_whose_operation_was_never_registered_does_not_raise(): void
    {
        // The strategy walks whatever is mounted; a mounted-but-unregistered op is a reportable absence for
        // the detector, not a reason to fail an entire spec build.
        $endpoint = $this->endpoint();

        $this->assertCount(1, $this->strategy()($endpoint));
        $this->assertSame(['type' => 'object'], $endpoint->custom['dataResponseSchemas'][0]['schema']);
    }
}
