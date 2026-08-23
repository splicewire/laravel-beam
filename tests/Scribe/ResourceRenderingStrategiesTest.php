<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Support\Facades\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Scribe\OpenApi\RenderingDeliveryGenerator;
use Splicewire\Beam\Scribe\Strategies\ResourceRenderingParameterStrategy;
use Splicewire\Beam\Scribe\Strategies\ResourceRenderingResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ResourceRenderingTitleStrategy;
use Splicewire\Beam\Tests\Fixtures\Rendering\RenderingSubject;
use Splicewire\Beam\Tests\Fixtures\Rendering\TranscriptRendering;
use Splicewire\Beam\Tests\TestCase;

/**
 * The three rendering strategies plus their document-assembly hook, end to end against a REAL mount —
 * api-surface-coherence ticket 32 §B/§C/§E.
 *
 * A rendering endpoint used to reach the reference as `queryParameters: []`, `responses: {}` and an
 * operationId shared with every other rendering in the estate. All three defects have one cause: the
 * route carried its whole contract on a `defaults` stamp and nothing at documentation time read it.
 */
class ResourceRenderingStrategiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RenderingSubject::reset();
        RenderingSubject::seed('doc-1', 'Hello');
        TranscriptRendering::$formats = ['text', 'html'];
    }

    private function mount(string $resource = 'papers'): ExtractedEndpointData
    {
        app(ResourceRenderingRegistry::class)->register($resource, new TranscriptRendering);

        Route::resourceRenderings($resource, RenderingSubject::class, abilities: [], idConstraint: 'none');
        Route::getRoutes()->refreshNameLookups();

        return ExtractedEndpointData::fromRoute(Route::getRoutes()->getByName("{$resource}.transcript"));
    }

    // ── §E · the operationId, via the title ───────────────────────────────────────────────────────────

    public function test_titles_off_the_stamp_so_one_controller_method_stops_producing_one_operation_id(): void
    {
        $endpoint = $this->mount();

        $strategy = new ResourceRenderingTitleStrategy(new DocumentationConfig([]));
        $result = $strategy($endpoint);

        // Both halves of the mount's identity ride the title, which is the ONLY input Scribe's
        // operationId derivation has — so uniqueness per (resource, rendering) is structural.
        $this->assertSame('Transcript Paper', $result['title']);
    }

    public function test_two_resources_sharing_a_rendering_name_title_apart(): void
    {
        $first = new ResourceRenderingTitleStrategy(new DocumentationConfig([]));

        $papers = $first($this->mount('papers'))['title'];
        $reports = $first($this->mount('reports'))['title'];

        $this->assertNotSame($papers, $reports);
    }

    public function test_clobbers_the_shared_controllers_docblock_summary_rather_than_deferring_to_it(): void
    {
        $endpoint = $this->mount();
        $endpoint->metadata->title = 'Read a rendering of the subject. Always mounted.';

        $strategy = new ResourceRenderingTitleStrategy(new DocumentationConfig([]));

        // The opposite of ParticleTitleStrategy's precedence, deliberately: the docblock that would win
        // here belongs to a controller shared by every rendering of every resource, so it is structurally
        // incapable of describing one route. Yielding to it is what produced three identical entries.
        $this->assertSame('Transcript Paper', $strategy($endpoint)['title']);
    }

    public function test_defers_for_a_route_carrying_no_rendering_stamp(): void
    {
        $plain = ExtractedEndpointData::fromRoute(Route::get('unrelated', fn () => null));

        $this->assertNull((new ResourceRenderingTitleStrategy(new DocumentationConfig([])))($plain));
        $this->assertNull((new ResourceRenderingParameterStrategy(new DocumentationConfig([])))($plain));
        $this->assertNull((new ResourceRenderingResponseStrategy(new DocumentationConfig([])))($plain));
    }

    // ── §B · the format parameter ─────────────────────────────────────────────────────────────────────

    public function test_documents_format_as_an_enum_of_the_live_set_with_the_declared_default(): void
    {
        $parameters = (new ResourceRenderingParameterStrategy(new DocumentationConfig([])))($this->mount());

        $this->assertSame(['text', 'html'], $parameters['format']['enumValues']);
        $this->assertSame('text', $parameters['format']['example']);
        $this->assertFalse($parameters['format']['required']);
    }

    public function test_omits_the_parameter_entirely_rather_than_publishing_a_zero_member_enum(): void
    {
        TranscriptRendering::$formats = [];

        $parameters = (new ResourceRenderingParameterStrategy(new DocumentationConfig([])))($this->mount());

        // The absent parameter IS the accurate description: no format axis, nothing validated, nobody
        // reading it. An empty enum would advertise a knob that does not exist.
        $this->assertSame([], $parameters);
    }

    // ── §C · the response contract ────────────────────────────────────────────────────────────────────

    public function test_writes_one_200_content_entry_per_declared_media_type_at_document_assembly(): void
    {
        $pathItem = $this->assemble($this->mount());

        // Scribe's own model drops a second response for a status it already holds when the content type
        // differs — which is why this lives in a generator and not in the strategy's return value.
        $this->assertSame(
            ['text/plain', 'text/html'],
            array_keys($pathItem['responses']['200']['content']),
        );
    }

    public function test_carries_the_renderings_own_response_headers_into_the_200(): void
    {
        $pathItem = $this->assemble($this->mount());

        $this->assertArrayHasKey('X-Rendering', $pathItem['responses']['200']['headers']);
    }

    public function test_gives_format_rejection_the_422_slot_it_had_none_of(): void
    {
        $pathItem = $this->assemble($this->mount());

        $errors = $pathItem['responses']['422']['content']['application/json']['schema']['properties']['errors'];

        $this->assertArrayHasKey('format', $errors['properties']);
    }

    public function test_writes_no_422_for_a_rendering_that_rejects_nothing(): void
    {
        TranscriptRendering::$formats = [];

        $pathItem = $this->assemble($this->mount());

        $this->assertArrayNotHasKey('422', $pathItem['responses']);
        $this->assertSame(['text/plain', 'text/html'], array_keys($pathItem['responses']['200']['content']));
    }

    public function test_writes_the_parameter_default_scribe_has_no_field_for(): void
    {
        $pathItem = $this->assemble($this->mount(), [
            ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string']],
        ]);

        $this->assertSame('text', $pathItem['parameters'][0]['schema']['default']);
    }

    public function test_leaves_a_non_rendering_endpoint_untouched(): void
    {
        $plain = OutputEndpointData::fromExtractedEndpointArray(
            ExtractedEndpointData::fromRoute(Route::get('unrelated', fn () => null))->toArray()
        );

        $pathItem = ['responses' => new \stdClass];

        $this->assertSame($pathItem, (new RenderingDeliveryGenerator(new DocumentationConfig([])))
            ->pathItem($pathItem, [], $plain));
    }

    /**
     * Run the response strategy and its assembly hook the way Scribe does: the strategy stashes onto the
     * endpoint's `custom` bag during extraction, the bag survives into `OutputEndpointData`, and the
     * generator reads it at document assembly — by which point the Laravel route is long gone.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private function assemble(ExtractedEndpointData $endpoint, array $parameters = []): array
    {
        (new ResourceRenderingResponseStrategy(new DocumentationConfig([])))($endpoint);

        $output = OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray());

        return (new RenderingDeliveryGenerator(new DocumentationConfig([])))->pathItem(
            ['parameters' => $parameters, 'responses' => new \stdClass],
            [],
            $output,
        );
    }
}
