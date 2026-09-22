<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Filters\DeclaredResourceQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/** Resource discovery distinguishes an empty vocabulary from an unknown or denied resource. */
class ResourceFilterSchemaDeclarationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    private function declareResource(string $key): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: SchemaDeclarationSubject::class, data: EmptySchemaDeclarationData::class, frame: true,
        ));
    }

    private function registerFilterResource(string $key): void
    {
        app(FilterResourceRegistry::class)->registerDefinition(new FilterResourceDefinition(
            key: $key,
            data: SchemaDeclarationFilterData::class,
            query: DeclaredResourceQuery::class,
        ));
    }

    public function test_read_data_attributes_supply_schema_and_a_canonical_variant_without_separate_registration(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'attributed-papers', backing: SchemaDeclarationSubject::class,
            data: AttributedPaperFilters::class, frame: true,
        ));

        $this->getJson('frame/resources/attributed-papers/filters/schema')->assertOk()
            ->assertJsonPath('data.properties.name.x-filter.operator', 'exact');
        $this->getJson('frame/resources/attributed-papers/filters/variants')->assertOk()
            ->assertJsonPath('data.variants.0.key', 'attributed-papers');
    }

    public function test_unframed_filter_registration_does_not_create_metadata_access(): void
    {
        $this->registerFilterResource('unframed-query');
        $this->getJson('frame/resources/unframed-query/filters/schema')->assertNotFound();
        $this->getJson('frame/resources/unframed-query/filters/variants')->assertNotFound();
        $this->declareResource('framed-empty');
        $this->getJson('frame/resources/framed-empty/filters/schema')->assertOk()->assertJsonPath('data.properties', []);
    }

    public function test_an_explicit_invalid_query_is_not_an_empty_capability(): void
    {
        $this->declareResource('broken-query');
        app(FilterResourceRegistry::class)->registerDefinition(new FilterResourceDefinition(
            key: 'broken-query', data: SchemaDeclarationFilterData::class,
            query: SchemaDeclarationSubject::class, model: SchemaDeclarationSubject::class,
        ));
        $this->getJson('frame/resources/broken-query/filters/schema')->assertStatus(500);
    }

    public function test_an_empty_resource_rejects_an_explicit_variant_and_undeclared_options(): void
    {
        $this->declareResource('empty-controls');
        $this->getJson('frame/resources/empty-controls/filters/absent/schema')->assertNotFound();
        $this->getJson('frame/resources/empty-controls/filters/options/absent')->assertNotFound();
    }

    public function test_the_data_filters_registry_is_a_singleton_in_this_harness(): void
    {
        // The tripwire, not a behaviour test. `ResourceRegistry` is auto-resolvable, so without
        // `Rushing\DataFilters\ServiceProvider` in getPackageProviders() a registration below would land
        // in a throwaway and every 404 assertion here would pass by not running.
        $this->assertSame(
            $this->app->make(FilterResourceRegistry::class),
            $this->app->make(FilterResourceRegistry::class),
        );
    }

    public function test_a_resource_that_declares_no_filter_surface_answers_an_empty_vocabulary(): void
    {
        $this->declareResource('opted-out-papers');

        $response = $this->withoutExceptionHandling()->getJson('frame/resources/opted-out-papers/filters/schema');

        $response->assertOk();
        $this->assertSame('object', $response->json('data.type'));
        $this->assertSame([], $response->json('data.properties'));
    }

    public function test_the_empty_vocabulary_encodes_properties_as_a_json_object(): void
    {
        // `[]` and `(object) []` are indistinguishable through `assertSame([], json(...))` — PHP decodes
        // both to an empty array — and a client reading `Object.values(schema.properties)` survives
        // either, which is exactly how the wrong one ships unnoticed. The wire contract
        // (`FilterSchema` in `@schemastud/facets`) says `properties` is an object, so assert the bytes.
        $this->declareResource('opted-out-bytes');

        $content = $this->withoutExceptionHandling()
            ->getJson('frame/resources/opted-out-bytes/filters/schema')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"properties":{}', $content);
        $this->assertStringNotContainsString('"properties":[]', $content);
    }

    public function test_a_resource_with_no_filter_registration_has_empty_metadata(): void
    {
        $this->declareResource('promised-papers');

        $this->getJson('frame/resources/promised-papers/filters/schema')->assertOk()->assertJsonPath('data.properties', []);
    }

    public function test_a_resource_that_declares_no_filter_surface_answers_an_empty_variant_list(): void
    {
        $this->declareResource('opted-out-variants');

        $response = $this->withoutExceptionHandling()->getJson('frame/resources/opted-out-variants/filters/variants');

        $response->assertOk();
        $this->assertSame('opted-out-variants', $response->json('data.resource'));
        $this->assertSame([], $response->json('data.variants'));
    }

    public function test_a_resource_with_no_filter_registration_has_empty_metadata_its_variants(): void
    {
        $this->declareResource('promised-variants');

        $this->getJson('frame/resources/promised-variants/filters/variants')->assertOk()->assertJsonPath('data.variants', []);
    }

    public function test_a_key_no_registry_carries_still_404s_its_variants(): void
    {

        $this->getJson('frame/resources/undeclared-variants/filters/variants')->assertNotFound();
    }

    public function test_a_registered_filter_resource_still_serves_its_canonical_variant(): void
    {
        $this->declareResource('kept-variants');
        $this->registerFilterResource('kept-variants');

        $response = $this->withoutExceptionHandling()->getJson('frame/resources/kept-variants/filters/variants');

        $response->assertOk();
        $this->assertSame('kept-variants', $response->json('data.resource'));
        $this->assertCount(1, $response->json('data.variants'));
        $this->assertTrue($response->json('data.variants.0.canonical'));
    }

    public function test_a_key_no_registry_carries_still_404s(): void
    {
        // Nothing is declared under this key in EITHER registry. 404 is true, and it is also what keeps
        // the sub-surface from confirming the existence of keys a caller guessed at.

        $this->getJson('frame/resources/undeclared-papers/filters/schema')->assertNotFound();
    }

    public function test_a_registered_filter_resource_still_serves_its_generated_vocabulary(): void
    {
        $this->declareResource('kept-papers');
        $this->registerFilterResource('kept-papers');

        $response = $this->withoutExceptionHandling()->getJson('frame/resources/kept-papers/filters/schema');

        $response->assertOk();
        $this->assertArrayHasKey('name', $response->json('data.properties'));
    }

    public function test_the_empty_vocabulary_is_gated_like_every_other_read_here(): void
    {
        // ⚠️ Stated because AGENTS.md's most expensive recorded defect is an authorization measurement
        // taken with the gate OPEN. This one is taken with it CLOSED: a real policy is bound and its
        // `viewAny` denies. Without it the whole file would be asserting that an UNGATED branch works,
        // which is exactly the shape that reports success by not running — the branch has no
        // `ResourceDefinition` to gate on and had to reach the model a different way.
        Gate::policy(SchemaDeclarationSubject::class, DenyingSchemaDeclarationPolicy::class);

        $this->declareResource('opted-out-gated');

        $this->actingAs(new SchemaDeclarationUser)
            ->getJson('frame/resources/opted-out-gated/filters/schema')
            ->assertForbidden();
    }

    public function test_the_frame_resource_root_reads_the_key_off_the_route_parameter(): void
    {
        // The wildcard mount — one route for every resource, the key IS the segment — is how the
        // flagship's operator realm reaches `tenants`, and it is the mount the browser measurement in
        // ticket 125 was taken against. The empty vocabulary has to reach it too.
        $this->declareResource('opted-out-wildcard');

        $this->withoutExceptionHandling()
            ->getJson('frame/resources/opted-out-wildcard/filters/schema')
            ->assertOk();

        // `withoutExceptionHandling()` is sticky for the rest of the test, and an abort() under it is a
        // thrown NotFoundHttpException rather than a 404 response — restore the handler before asking
        // for the status.
        $this->withExceptionHandling()
            ->getJson('frame/resources/nothing-declared-here/filters/schema')
            ->assertNotFound();
    }
}

/** Backing + query stand-in. A plain class: `BackingResolver` reads it as a model class-string. */
class SchemaDeclarationSubject {}

/** The CLOSED gate. `viewAny` is the ability this sub-surface asks for; denying it must reach the wire. */
class DenyingSchemaDeclarationPolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}

/** An authenticatable, so the gate has a subject to deny rather than a guest to redirect. */
class SchemaDeclarationUser extends AuthUser {}

/** A filter Data class with one facet, so the generated-vocabulary case has something to generate. */
class SchemaDeclarationFilterData extends Data
{
    public function __construct(
        public ?string $name = null,
    ) {}
}

class EmptySchemaDeclarationData extends Data {}

class AttributedPaperFilters extends Data
{
    public function __construct(
        #[Filterable(operator: Exact::class)]
        public ?string $name = null,
    ) {}
}
