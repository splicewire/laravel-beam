<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\InMemoryResourceRegistry;
use Schemastud\Frame\Registry\NavMetadata;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Filters\DeclaredResourceQuery;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class ResourceFilterDefinitionTest extends TestCase
{
    public function test_inferred_declarations_resolve_the_current_scopes_model(): void
    {
        $this->app->scoped(DefinitionModelChoice::class, fn () => new DefinitionModelChoice(DefinitionModelA::class));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'scoped-model', backing: DefinitionScopedBacking::class, data: DefinitionFilterData::class,
        ));
        $resolver = app(ResourceFilterDefinition::class);
        $this->assertSame(DefinitionModelA::class, $resolver->definition('scoped-model')->model);
        $this->assertNull(DataFilter::registry()->get('scoped-model')->model);

        $this->app->forgetScopedInstances();
        $this->app->scoped(DefinitionModelChoice::class, fn () => new DefinitionModelChoice(DefinitionModelB::class));
        $this->assertSame(DefinitionModelB::class, $resolver->definition('scoped-model')->model);
        $this->assertInstanceOf(DefinitionModelB::class, DataFilter::query('scoped-model')->authorizationQuery(Request::create('/'))->getModel());
    }

    public function test_an_explicit_query_cannot_filter_a_different_models_ids(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'wrong-canonical-model', backing: DefinitionModelA::class, data: DefinitionFilterData::class,
        ));
        DataFilter::registry()->registerDefinition(new ResourceDefinition(
            key: 'wrong-canonical-model', data: DefinitionFilterData::class,
            query: DeclaredResourceQuery::class, model: DefinitionModelB::class,
        ));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('different model from its resource backing');
        app(ResourceFilterDefinition::class)->definition('wrong-canonical-model');
    }

    public function test_a_frame_only_query_cannot_filter_a_different_models_ids(): void
    {
        $frames = new InMemoryResourceRegistry;
        $frames->register(new \Schemastud\Frame\Registry\ResourceDefinition(
            key: 'frame-model', model: DefinitionModelA::class, data: DefinitionFilterData::class,
            creatable: false, query: null, editData: null, policy: null, form: 'bare',
            nav: new NavMetadata('Frame model'),
        ));
        $this->app->instance(ResourceRegistry::class, $frames);
        DataFilter::registry()->registerDefinition(new ResourceDefinition(
            key: 'frame-model', data: DefinitionFilterData::class,
            query: DeclaredResourceQuery::class, model: DefinitionModelB::class,
        ));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('different model from its resource backing');
        app(ResourceFilterDefinition::class)->definition('frame-model');
    }

    public function test_an_explicit_query_on_a_stream_is_not_silently_ignored(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'invalid-stream-query', backing: DefinitionStreamBacking::class,
            data: DefinitionFilterData::class, query: DeclaredResourceQuery::class, readOnly: true,
        ));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without an exclusive queryable Data definition');
        app(ResourceFilterDefinition::class)->definition('invalid-stream-query');
    }

    public function test_an_explicit_query_without_data_is_not_an_empty_declaration(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'missing-query-data', backing: DefinitionModelA::class, query: DeclaredResourceQuery::class,
        ));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without an exclusive queryable Data definition');
        app(ResourceFilterDefinition::class)->definition('missing-query-data');
    }
}

class DefinitionModelChoice
{
    public function __construct(public string $model) {}
}

class DefinitionScopedBacking extends EloquentBacking
{
    public function __construct(DefinitionModelChoice $choice)
    {
        parent::__construct($choice->model);
    }
}

class DefinitionStreamBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class DefinitionModelA extends Model {}
class DefinitionModelB extends Model {}

class DefinitionFilterData extends Data
{
    public function __construct(
        #[Filterable(operator: Exact::class)]
        public string $name,
    ) {}
}
