<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Extractor;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleTitleStrategy;
use Splicewire\Beam\Scribe\Strategies\RouteTitleStrategy;
use Splicewire\Beam\Tests\TestCase;

#[Endpoint('Launch a circuit')]
class ExplicitOperationTitleController extends ParticleOperationController {}

class AuthoredOperationTitleController extends ParticleOperationController
{
    /** Run the selected circuit */
    public function invoke(Request $request, ?string $id = null): mixed
    {
        return null;
    }
}

class EndpointTitleExtractionTest extends TestCase
{
    private function extract(Route $route): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig([
            'strategies' => [
                'metadata' => [...Defaults::METADATA_STRATEGIES, ParticleTitleStrategy::class, RouteTitleStrategy::class],
                'urlParameters' => [],
                'queryParameters' => [],
                'headers' => [],
                'bodyParameters' => [],
                'responses' => [],
                'responseFields' => [],
            ],
        ])))->processRoute($route);
    }

    private function operationRoute(string $controller = ParticleOperationController::class): Route
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'circuits',
            name: 'run',
            kind: OperationKind::Task,
            model: 'App\\Models\\Fixture',
            handle: fn () => null,
        ));

        return (new Route(['POST'], 'circuits/{id}/run', [
            'uses' => $controller.'@invoke',
            'controller' => $controller.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'circuits')
            ->defaults(ParticleOperationController::NAME, 'run')
            ->name('circuits.run');
    }

    public function test_generic_operation_comments_do_not_become_endpoint_titles(): void
    {
        $endpoint = $this->extract($this->operationRoute());

        $this->assertSame('Run Circuit', $endpoint->metadata->title);
        $this->assertStringContainsString('`?async`', $endpoint->metadata->description);
        $this->assertStringNotContainsString('nullable', $endpoint->metadata->description);
    }

    public function test_explicit_endpoint_attributes_override_inherited_generic_comments(): void
    {
        $endpoint = $this->extract($this->operationRoute(ExplicitOperationTitleController::class));

        $this->assertSame('Launch a circuit', $endpoint->metadata->title);
    }

    public function test_host_method_summaries_remain_authoritative(): void
    {
        $endpoint = $this->extract($this->operationRoute(AuthoredOperationTitleController::class));

        $this->assertSame('Run the selected circuit', $endpoint->metadata->title);
    }

    public function test_an_unstamped_generic_handler_uses_the_route_name(): void
    {
        $route = $this->operationRoute();
        $route->defaults = [];

        $this->assertSame('Run Circuit', $this->extract($route)->metadata->title);
    }

    public function test_frame_endpoint_titles_describe_actions_and_exclude_implementation_notes(): void
    {
        foreach ([
            'schema' => 'Get Resource Schema',
            'destroy' => 'Delete Resource',
            'filterSchema' => 'Get Filter Schema',
            'savedFilters' => 'List Saved Filters',
        ] as $method => $title) {
            $action = FrameResourceController::class.'@'.$method;
            $endpoint = $this->extract(new Route(['GET'], 'frame/resources/{resource}', [
                'uses' => $action,
                'controller' => $action,
            ]));

            $this->assertSame($title, $endpoint->metadata->title);
            $this->assertStringNotContainsString('~/Herd', $endpoint->metadata->description);
            $this->assertStringNotContainsString('widest of the three', $endpoint->metadata->description);
        }
    }
}
