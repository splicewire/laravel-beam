<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\FilterablePromiseAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `GET /<resource>/filters/schema` for a resource that DECLARES no filter surface
 * (api-surface-coherence ticket 125).
 *
 * The sub-surface used to answer 404 for every `DataFilter::tryResource()` miss, which conflated two
 * structurally different facts: *"no such resource"* and *"this resource declared `filterable: false`"*.
 * The second is not an absence — `tenants` and `scaffold-packs` are registered particle resources the
 * same page already read out of the frame manifest — and the 404 was a permanent, expected error on
 * every mount of their list pages.
 *
 * ⚠️ Every case here asserts what STAYS 404 as well as what stops being one. The narrowing is the whole
 * decision: an unknown key and a `filterable: true` resource with no data-filters registration (the
 * promise made by not opting out — {@see FilterablePromiseAudit}) both keep
 * their 404. A test suite that only asserted the 200 would pass against a controller that had simply
 * stopped 404-ing, which is the version of this change that hides a live defect and re-opens the
 * enumeration leak the class docblock exists to describe.
 *
 * Routes are mounted through `Particle::filters()` — never by hand — because the resource is read off
 * the route's frozen config and a hand-mounted route has none (the controller says so, loudly).
 */
class ResourceFilterSchemaDeclarationTest extends TestCase
{
    private function declareResource(string $key, bool $filterable): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: SchemaDeclarationSubject::class,
            filterable: $filterable,
        ));
    }

    private function registerFilterResource(string $key): void
    {
        app(FilterResourceRegistry::class)->registerDefinition(new FilterResourceDefinition(
            key: $key,
            data: SchemaDeclarationFilterData::class,
            query: SchemaDeclarationSubject::class,
        ));
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
        $this->declareResource('opted-out-papers', filterable: false);
        Particle::filters('opted-out-papers', at: 'opted-out-papers');

        $response = $this->withoutExceptionHandling()->getJson('opted-out-papers/filters/schema');

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
        $this->declareResource('opted-out-bytes', filterable: false);
        Particle::filters('opted-out-bytes', at: 'opted-out-bytes');

        $content = $this->withoutExceptionHandling()
            ->getJson('opted-out-bytes/filters/schema')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"properties":{}', $content);
        $this->assertStringNotContainsString('"properties":[]', $content);
    }

    public function test_a_filterable_resource_with_no_registration_still_404s(): void
    {
        // THE PROMISE BREACH. `filterable` defaults to true, so this declaration says "I ride a
        // data-filters query" by not opting out — and there is none. Its index raises; its filter
        // sub-surface must keep saying 404, or the wire quietly hides a live defect the doctor audit
        // exists to report.
        $this->declareResource('promised-papers', filterable: true);
        Particle::filters('promised-papers', at: 'promised-papers');

        $this->getJson('promised-papers/filters/schema')->assertNotFound();
    }

    public function test_a_key_no_registry_carries_still_404s(): void
    {
        // Nothing is declared under this key in EITHER registry. 404 is true, and it is also what keeps
        // the sub-surface from confirming the existence of keys a caller guessed at.
        Particle::filters('undeclared-papers', at: 'undeclared-papers');

        $this->getJson('undeclared-papers/filters/schema')->assertNotFound();
    }

    public function test_a_registered_filter_resource_still_serves_its_generated_vocabulary(): void
    {
        // The negative case for the branch: a resource that HAS a data-filters registration must never
        // reach the empty vocabulary, whatever its `filterable` flag says. `review-queue` at the
        // flagship is exactly this shape — `filterable: false` AND registered — and gating on the flag
        // alone would have deleted its real schema (and with it its sortable fields).
        $this->declareResource('kept-papers', filterable: false);
        $this->registerFilterResource('kept-papers');
        Particle::filters('kept-papers', at: 'kept-papers');

        $response = $this->withoutExceptionHandling()->getJson('kept-papers/filters/schema');

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

        $this->declareResource('opted-out-gated', filterable: false);
        Particle::filters('opted-out-gated', at: 'opted-out-gated');

        $this->actingAs(new SchemaDeclarationUser)
            ->getJson('opted-out-gated/filters/schema')
            ->assertForbidden();
    }

    public function test_the_frame_resource_root_reads_the_key_off_the_route_parameter(): void
    {
        // The wildcard mount — one route for every resource, the key IS the segment — is how the
        // flagship's operator realm reaches `tenants`, and it is the mount the browser measurement in
        // ticket 125 was taken against. The empty vocabulary has to reach it too.
        $this->declareResource('opted-out-wildcard', filterable: false);
        Particle::filters(null, at: 'resources/{resource}', names: 'resources');

        $this->withoutExceptionHandling()
            ->getJson('resources/opted-out-wildcard/filters/schema')
            ->assertOk();

        // `withoutExceptionHandling()` is sticky for the rest of the test, and an abort() under it is a
        // thrown NotFoundHttpException rather than a 404 response — restore the handler before asking
        // for the status.
        $this->withExceptionHandling()
            ->getJson('resources/nothing-declared-here/filters/schema')
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
