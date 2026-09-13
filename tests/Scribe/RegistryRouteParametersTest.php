<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Extracting\Extractor;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\BaseGenerator;
use Rushing\DataFilters\Options\OptionsRegistry;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class RegistryRouteFilterData extends Data
{
    public function __construct(public ?string $title = null) {}
}

class RegistryRouteQuery extends ResourceQuery {}

class RegistryRouteBacking implements DeclaresFilterVocabulary
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('owner', options: 'paper-owners'));
    }
}

enum RegistryRouteChoice: string
{
    case First = 'first';
    case Second = 'second';
}

class RegistryRouteChoiceController
{
    public function show(RegistryRouteChoice $choice): void {}
}

class RegistryRouteParametersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['papers' => 'papers', 'journals' => 'papers', 'threads' => 'threads'] as $key => $resource) {
            app(ResourceRegistry::class)->registerDefinition(new ResourceDefinition(
                key: $key, data: RegistryRouteFilterData::class, query: RegistryRouteQuery::class,
                model: 'UnusedModel', resource: $resource,
            ));
        }
        Particle::filters('papers', at: 'publications');
        Particle::filters(null, at: 'frame/resources/{resource}', names: 'dynamic');
    }

    private function endpoint(string $path): ExtractedEndpointData
    {
        $route = Route::getRoutes()->match(Request::create($path));
        $config = new DocumentationConfig([
            'strategies' => [
                'metadata' => [],
                'urlParameters' => (require __DIR__.'/../../stubs/scribe/scribe.php')['strategies']['urlParameters'],
                'queryParameters' => [], 'bodyParameters' => [], 'headers' => [], 'responses' => [], 'responseFields' => [],
            ],
        ]);

        return (new Extractor($config))->processRoute($route, []);
    }

    public function test_closure_routes_can_publish_native_enum_parameters(): void
    {
        Route::get('closure-choices/{choice}', fn (RegistryRouteChoice $choice): string => $choice->value);
        $endpoint = $this->endpoint('/closure-choices/first');
        $this->assertSame(['first', 'second'], $endpoint->urlParameters['choice']->enumValues);
    }

    public function test_filter_variant_enum_reaches_openapi_and_agrees_with_the_route(): void
    {
        $endpoint = $this->endpoint('/publications/filters/journals/schema');
        $this->assertSame(['variant'], array_keys($endpoint->urlParameters));
        $this->assertSame(['journals', 'papers'], $endpoint->urlParameters['variant']->enumValues);
        $this->assertSame('journals', $endpoint->urlParameters['variant']->example);
        $output = new OutputEndpointData($endpoint->toArray());
        $base = new BaseGenerator(new DocumentationConfig([]));
        $bridge = new DataSchemaGenerator(new DocumentationConfig([]));
        $parameters = $base->pathParameters([], [$output], $output->urlParameters);
        $parameters = $bridge->pathParameters($parameters, [$output], $output->urlParameters);
        $this->assertSame(['journals', 'papers'], $parameters['variant']['schema']['enum']);
        $operation = $bridge->pathItem([], [], $output);
        $this->assertSame(['journals', 'papers'], $operation['parameters'][0]['schema']['enum']);
        $this->getJson('/publications/filters/journals/schema')->assertOk();
        $this->getJson('/publications/filters/threads/schema')->assertNotFound();
        $this->getJson('/publications/filters/Unknown/schema')->assertNotFound();
    }

    public function test_the_frame_mount_reads_variant_by_name_and_keeps_resource_dependency(): void
    {
        $endpoint = $this->endpoint('/frame/resources/papers/filters/journals/schema');
        $this->assertSame(['resource', 'variant'], array_keys($endpoint->urlParameters));
        $this->assertSame(['hooks', 'journals', 'papers', 'threads'], $endpoint->urlParameters['resource']->enumValues);
        $this->assertStringContainsString('depends on the resource', $endpoint->urlParameters['variant']->description);
        $this->getJson('/frame/resources/papers/filters/journals/schema')->assertOk();
        $this->getJson('/frame/resources/papers/filters/threads/schema')->assertNotFound();
        $this->getJson('/frame/resources/papers/filters/variants')->assertJsonPath('data.variants.0.key', 'journals');
    }

    public function test_schema_resources_include_declarations_without_a_filter_dto(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'queue', backing: RegistryRouteBacking::class, readOnly: true,
        ));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'unfiltered', backing: User::class, filterable: false,
        ));
        $schema = $this->endpoint('/frame/resources/queue/filters/schema');
        $this->assertContains('queue', $schema->urlParameters['resource']->enumValues);
        $this->assertContains('unfiltered', $schema->urlParameters['resource']->enumValues);
        $variants = $this->endpoint('/frame/resources/papers/filters/variants');
        $this->assertNotContains('queue', $variants->urlParameters['resource']->enumValues);
        $this->assertNotContains('unfiltered', $variants->urlParameters['resource']->enumValues);
        $this->getJson('/frame/resources/queue/filters/schema')->assertOk();
        $this->getJson('/frame/resources/unfiltered/filters/schema')->assertOk();
    }

    public function test_options_document_handles_without_resolving_option_rows(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'queue', backing: RegistryRouteBacking::class, readOnly: true,
        ));
        $resolutions = 0;
        app(OptionsRegistry::class)->register('paper-owners', function () use (&$resolutions): array {
            $resolutions++;

            return [];
        });
        app(OptionsRegistry::class)->register('other-owners', fn (): array => []);
        $endpoint = $this->endpoint('/frame/resources/queue/filters/options/paper-owners');
        $this->assertContains('queue', $endpoint->urlParameters['resource']->enumValues);
        $this->assertContains('paper-owners', $endpoint->urlParameters['ref']->enumValues);
        $this->assertNull($endpoint->urlParameters['ref']->example);
        $this->assertSame(0, $resolutions);
        $this->getJson('/frame/resources/queue/filters/options/other-owners')->assertNotFound();
        $this->getJson('/frame/resources/queue/filters/options/paper-owners')->assertOk();
        $this->assertSame(1, $resolutions);
    }

    public function test_saved_filter_id_is_not_described_as_the_parent_resource_id(): void
    {
        $endpoint = $this->endpoint('/publications/filters/00000000-0000-4000-8000-000000000000');
        $this->assertSame(['id'], array_keys($endpoint->urlParameters));
        $this->assertSame('The ID of the saved filter.', $endpoint->urlParameters['id']->description);
    }

    public function test_unrelated_model_resolution_does_not_break_variant_discovery(): void
    {
        app(ResourceRegistry::class)->registerDefinition(new ResourceDefinition(
            key: 'broken', data: RegistryRouteFilterData::class, query: 'MissingQuery', resource: 'broken',
        ));
        $endpoint = $this->endpoint('/publications/filters/journals/schema');
        $this->assertSame(['journals', 'papers'], $endpoint->urlParameters['variant']->enumValues);
        $this->getJson('/publications/filters/variants')->assertOk()->assertJsonCount(2, 'data.variants');
    }

    public function test_native_enum_path_parameters_reach_the_same_openapi_bridge(): void
    {
        Route::get('choices/{choice}', [RegistryRouteChoiceController::class, 'show']);
        $endpoint = $this->endpoint('/choices/first');
        $this->assertSame(['first', 'second'], $endpoint->urlParameters['choice']->enumValues);
        $output = new OutputEndpointData($endpoint->toArray());
        $operation = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], [], $output);
        $this->assertSame(['first', 'second'], $operation['parameters'][0]['schema']['enum']);
    }

    public function test_frame_saved_filter_actions_read_the_id_by_name(): void
    {
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('resource');
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->json('query_parameters')->nullable();
            $table->string('visibility');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $user = new User;
        $user->setRawAttributes(['id' => 42]);
        $this->actingAs($user);
        $saved = SavedFilter::create([
            'name' => 'Reading list', 'resource' => 'papers', 'owner_type' => $user->getMorphClass(),
            'owner_id' => 42, 'visibility' => 'private',
        ]);
        $uri = '/frame/resources/papers/filters/'.$saved->id;
        $this->getJson($uri)->assertOk()->assertJsonPath('data.name', 'Reading list');
        $this->putJson($uri, ['name' => 'Revised list'])->assertOk()->assertJsonPath('data.name', 'Revised list');
        $this->deleteJson($uri)->assertNoContent();
        $this->getJson($uri)->assertNotFound();
    }

    public function test_empty_vocabulary_remains_impossible_in_openapi(): void
    {
        Particle::filters('unknown', at: 'empty');
        $endpoint = $this->endpoint('/empty/filters/anything/schema');
        $this->assertNull($endpoint->urlParameters['variant']->example);
        $operation = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], [], new OutputEndpointData($endpoint->toArray()));
        $this->assertInstanceOf(\stdClass::class, $operation['parameters'][0]['schema']['not']);
        $this->assertArrayNotHasKey('example', $operation['parameters'][0]);
    }
}
