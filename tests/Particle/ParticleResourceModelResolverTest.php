<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Rushing\DataFilters\Attributes\ResourceFilter;
use Rushing\DataFilters\Contracts\ResourceModelResolver;
use Rushing\DataFilters\Discovery\AttributedResourceFilterDiscovery;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Spatie\LaravelData\Data;
use Spatie\QueryBuilder\QueryBuilderServiceProvider;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceModelResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam's half of data-filters' model-resolver port (its ADR-0008): a `#[ResourceFilter]` may omit
 * `model:` and have it resolved from the `#[ParticleResource]` declared under the same key, rather
 * than restating it on the Filter Data class.
 */
class ParticleResourceModelResolverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            QueryBuilderServiceProvider::class,
            DataFiltersServiceProvider::class,
        ];
    }

    public function test_the_resolver_is_bound_by_beams_provider(): void
    {
        $this->assertInstanceOf(
            ParticleResourceModelResolver::class,
            $this->app->make(ResourceModelResolver::class),
        );
    }

    public function test_it_returns_the_model_of_a_registered_particle_resource(): void
    {
        $this->app->make(ParticleResourceRegistry::class)
            ->registerClass(ResolvableGizmoData::class);

        $this->assertSame(
            ResolvableGizmo::class,
            $this->app->make(ResourceModelResolver::class)->resolveModel('resolvable-gizmo'),
        );
    }

    public function test_it_returns_null_for_an_unregistered_key_rather_than_throwing(): void
    {
        $this->assertNull(
            $this->app->make(ResourceModelResolver::class)->resolveModel('no-such-resource'),
        );
    }

    /**
     * The loop actually closing: a Filter Data class declaring NO model, whose key matches a
     * separately-registered `#[ParticleResource]`, resolves end to end through this binding. That the
     * particle resource is registered AFTER discovery has already run is the point — resolution is
     * deferred to resolve time precisely so registration order can't matter.
     */
    public function test_a_resource_filter_with_no_model_resolves_end_to_end(): void
    {
        (new AttributedResourceFilterDiscovery($this->app->make(ResourceRegistry::class)))
            ->discover(classes: [ResolvableGizmoFilterData::class]);

        $this->app->make(ParticleResourceRegistry::class)
            ->registerClass(ResolvableGizmoData::class);

        $this->assertSame(
            ResolvableGizmo::class,
            DataFilter::resource('resolvable-gizmo')->model,
        );
    }
}

class ResolvableGizmo extends Model
{
    protected $table = 'resolvable_gizmos';

    protected $guarded = [];
}

/** The read Data class — carries the model declaration, under the shared key. */
#[ParticleResource(key: 'resolvable-gizmo', model: ResolvableGizmo::class)]
class ResolvableGizmoData extends Data
{
    public function __construct(public int $id, public string $name) {}
}

class ResolvableGizmoQuery extends ResourceQuery {}

/** The Filter Data class — same key, deliberately no `model:`. */
#[ResourceFilter(key: 'resolvable-gizmo', query: ResolvableGizmoQuery::class)]
class ResolvableGizmoFilterData extends Data
{
    public function __construct(public ?string $name = null) {}
}
