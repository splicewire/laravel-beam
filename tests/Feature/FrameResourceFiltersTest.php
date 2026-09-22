<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterDefinition;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Routing\ResourceRoutes;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Filters\BeamResourceFilterProvider;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\SavedFilterResourceHandler;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\TestCase;

class FrameResourceFiltersTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__, 2).'/database/migrations/shared/create_saved_filters_table.php.stub')->up();
        foreach (['papers', 'books'] as $key) {
            $this->registerTarget($key);
        }
        $this->actingAs($this->actor(1));
    }

    private function actor(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function registerTarget(string $key, string $backing = FilterRecord::class): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key, backing: $backing, data: ResourceFilterData::class, frame: true, readOnly: true,
        ));
        DataFilter::registry()->registerDefinition(new FilterDefinition(
            key: $key, data: ResourceFilterData::class, query: ResourceFilterQuery::class, model: FilterRecord::class,
        ));
    }

    private function payload(string $resource = 'papers', array $extra = []): array
    {
        return array_replace(['name' => 'View', 'resource' => $resource, 'query_parameters' => ['filter' => ['count' => '12']]], $extra);
    }

    private function create(string $target = 'papers', array $extra = []): string
    {
        return $this->postJson('frame/resources/saved-filters', $this->payload($target, $extra))->assertSuccessful()->json('data.id');
    }

    public function test_beam_projects_the_capability_and_a_contextual_saved_filter_resource(): void
    {
        $this->assertInstanceOf(RealmEntitlementResourceGate::class, app(ResourceAccessGate::class));
        $registry = app(ResourceRegistry::class);
        $this->assertSame(BeamResourceFilterProvider::class, $registry->get('papers')->filterProvider);
        $saved = $registry->get('saved-filters');
        $this->assertSame(SavedFilter::class, $saved->model);
        $this->assertSame(SavedFilterData::class, $saved->data);
        $this->assertSame('', $saved->nav->label);
        $this->assertNull($saved->nav->section);
        $this->assertInstanceOf(SavedFilterResourceHandler::class, app(FrameResourceHandlerResolver::class)->handlerFor('saved-filters'));
        $this->assertNotNull(Gate::getPolicyFor(SavedFilter::class));
        $this->assertArrayNotHasKey('filterProvider', $registry->get('papers')->toArray());
        $this->getJson('frame/resources/papers/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', 'saved-filters');
    }

    public function test_canonical_saved_resource_creates_reads_updates_and_deletes(): void
    {
        $id = $this->create();
        $this->getJson('frame/resources/saved-filters?filter[resource]=papers')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.query_parameters.filter.count', 12);
        $this->putJson("frame/resources/saved-filters/records/{$id}", ['name' => 'Updated', 'query_parameters' => ['filter' => ['count' => '7']]])->assertOk();
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.query_parameters.filter.count', 7);
        $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertNoContent();
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertNotFound();
    }

    public function test_parallel_metadata_and_saved_routes_are_absent(): void
    {
        $id = $this->create();
        foreach (['papers/filters', 'papers/filters/schema', 'papers/filters/variants', 'papers/filters/options/paper-counts', "papers/filters/{$id}"] as $url) {
            $this->getJson($url)->assertNotFound();
        }
        $this->postJson('papers/filters', $this->payload())->assertNotFound();
        $this->putJson("papers/filters/{$id}", ['name' => 'Forbidden path'])->assertNotFound();
        $this->deleteJson("papers/filters/{$id}")->assertNotFound();
        $this->assertSame('View', SavedFilter::findOrFail($id)->name);
    }

    public function test_empty_queries_serialize_as_objects_without_storage_metadata(): void
    {
        $created = $this->postJson('frame/resources/saved-filters', ['name' => 'Empty', 'resource' => 'papers'])->assertSuccessful();
        $id = $created->json('data.id');
        $this->assertInstanceOf(\stdClass::class, json_decode($created->getContent())->data->query_parameters);
        $this->assertSame([], SavedFilter::findOrFail($id)->query_parameters);
        $read = $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk();
        $updated = $this->putJson("frame/resources/saved-filters/records/{$id}", ['name' => 'Still empty'])->assertOk();
        foreach ([$read, $updated] as $response) {
            $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->data->query_parameters);
            foreach (['owner_type', 'owner_id', 'context_type', 'context_id', 'created_at', 'updated_at'] as $field) {
                $this->assertArrayNotHasKey($field, $response->json('data'));
            }
        }
        $list = $this->getJson('frame/resources/saved-filters?filter[resource]=papers')->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($list->getContent())->data[0]->query_parameters);
        SavedFilter::findOrFail($id)->update(['owner_type' => null, 'owner_id' => null, 'visibility' => 'public']);
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.visibility', 'public')->assertJsonPath('data.can.delete', false);
    }

    public function test_index_requires_a_target_and_paginates_without_cross_target_rows(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->create();
        }
        $this->create('books');
        $this->getJson('frame/resources/saved-filters')->assertUnprocessable();
        $this->getJson('frame/resources/saved-filters?filter[resource]=papers')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 27);
        $this->getJson('frame/resources/saved-filters?filter[resource]=papers&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 27);
        $this->getJson('frame/resources/saved-filters?filter[resource]=books')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('frame/resources/saved-filters', ['name' => 'No target'])->assertUnprocessable();
    }

    public function test_visibility_and_mutation_require_ownership(): void
    {
        $private = $this->create();
        $shared = $this->create(extra: ['visibility' => 'shared']);
        $public = $this->create(extra: ['visibility' => 'public']);
        $this->actingAs($this->actor(2));
        $this->getJson('frame/resources/saved-filters?filter[resource]=papers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("frame/resources/saved-filters/records/{$private}")->assertNotFound();
        $this->getJson("frame/resources/saved-filters/records/{$shared}")->assertOk();
        $this->getJson("frame/resources/saved-filters/records/{$public}")->assertOk();
        foreach ([$private, $shared, $public] as $id) {
            $this->putJson("frame/resources/saved-filters/records/{$id}", [])->assertNotFound();
            $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertNotFound();
        }
    }

    public function test_target_access_precedes_validation_for_reads_and_writes(): void
    {
        $id = $this->create();
        $this->app->bind(ResourceAccessGate::class, fn () => new class implements ResourceAccessGate
        {
            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $definition->key !== 'papers';
            }
        });
        foreach (['frame/resources/papers/filters/schema', 'frame/resources/saved-filters?filter[resource]=papers', "frame/resources/saved-filters/records/{$id}"] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('frame/resources/saved-filters', ['resource' => 'papers'])->assertForbidden();
        $this->putJson("frame/resources/saved-filters/records/{$id}", [])->assertForbidden();
        $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertForbidden();
        $this->create('books');
    }

    public function test_existing_target_policy_is_still_required_after_frame_reach(): void
    {
        $id = $this->create();
        Gate::policy(FilterRecord::class, DeniedFilterPolicy::class);
        $this->postJson('frame/resources/saved-filters', ['resource' => 'papers'])->assertForbidden();
        $this->getJson('frame/resources/papers/filters/schema')->assertForbidden();

        $path = "frame/resources/saved-filters/records/{$id}";
        $this->getJson($path)->assertForbidden();
        $this->putJson($path, [])->assertForbidden();
        $this->deleteJson($path)->assertForbidden();
    }

    public function test_target_is_immutable_and_invalid_queries_and_values_do_not_persist(): void
    {
        $id = $this->create();
        $this->putJson("frame/resources/saved-filters/records/{$id}", $this->payload('books'))->assertUnprocessable();

        foreach ([['filter' => ['unknown' => 1]], ['filter' => ['count' => 'no-number']], ['filter' => 'bad'], ['sort' => ['nested' => []]], ['include' => 'unknown'], ['limit' => 'bad'], ['unrecognized' => true]] as $query) {
            $this->postJson('frame/resources/saved-filters', $this->payload(extra: ['query_parameters' => $query]))->assertUnprocessable();
        }
        $this->assertSame(1, SavedFilter::count());
        $this->assertSame('papers', SavedFilter::first()->resource);
    }

    public function test_default_demotion_is_scoped_to_owner_and_target(): void
    {
        $first = $this->create(extra: ['is_default' => true]);
        $book = $this->create('books', ['is_default' => true]);
        $this->actingAs($this->actor(2));
        $other = $this->create(extra: ['is_default' => true]);
        $this->actingAs($this->actor(1));
        $last = $this->create(extra: ['name' => 'New default', 'is_default' => true]);
        $this->assertFalse(SavedFilter::findOrFail($first)->is_default);
        foreach ([$book, $other, $last] as $id) {
            $this->assertTrue(SavedFilter::findOrFail($id)->is_default);
        }
    }

    public function test_options_are_confined_to_model_vocabulary_and_search_is_forwarded(): void
    {
        DataFilter::options('paper-counts', fn (?string $search) => [['value' => '1', 'label' => $search ?? 'All']]);
        DataFilter::options('other-secrets', fn () => throw new \RuntimeException('Unreferenced source must not execute'));
        $this->getJson('frame/resources/papers/filters/options/paper-counts?search=needle')->assertOk()->assertJsonPath('data.0.label', 'needle');
        $this->getJson('frame/resources/papers/filters/options/other-secrets')->assertNotFound();
        $this->getJson('frame/resources/papers/filters/options/paper-counts?search[]=bad')->assertUnprocessable();
    }

    public function test_variants_and_their_option_handles_stay_with_their_target_resource(): void
    {
        DataFilter::registry()->registerDefinition(new FilterDefinition(
            key: 'recent-papers', data: VariantFilterData::class, query: ResourceFilterQuery::class,
            model: FilterRecord::class, resource: 'papers',
        ));
        DataFilter::options('paper-statuses', fn () => [['value' => 'recent', 'label' => 'Recent']]);
        $this->getJson('frame/resources/papers/filters/variants')->assertOk()->assertJsonCount(2, 'data.variants');
        $this->getJson('frame/resources/papers/filters/recent-papers/schema')->assertOk()->assertJsonPath('data.properties.status.x-filter.optionsRef', 'paper-statuses');
        $this->getJson('frame/resources/papers/filters/options/paper-statuses')->assertOk();
        $this->getJson('frame/resources/books/filters/options/paper-statuses')->assertNotFound();
        $this->getJson('frame/resources/books/filters/recent-papers/schema')->assertNotFound();
    }

    public function test_custom_host_handler_path_checks_the_saved_filter_policy_itself(): void
    {
        Route::post('host/saved', function (Request $request) {
            return app(SavedFilterResourceHandler::class)->store(app(ResourceRegistry::class)->get('saved-filters'), $request->all());
        });
        Gate::policy(SavedFilter::class, DeniedSavedFilterPolicy::class);
        $this->postJson('host/saved', ['resource' => 'papers'])->assertForbidden();
        $this->assertSame(0, SavedFilter::count());
    }

    public function test_custom_handler_reads_and_mutations_enforce_target_access_and_realm_membership(): void
    {
        $id = $this->create('books');
        app(ParticleResourceRegistry::class)->loadRealmMap(['tenant' => ['papers', 'saved-filters'], 'operator' => ['books', 'saved-filters']]);
        Route::prefix('{realm}/host')->group(function (): void {
            Route::get('saved', fn () => app(SavedFilterResourceHandler::class)->index(app(ResourceRegistry::class)->get('saved-filters'), request()->query()));
            Route::get('saved/{id}', fn () => app(SavedFilterResourceHandler::class)->show(app(ResourceRegistry::class)->get('saved-filters'), request()->route('id')));
            Route::put('saved/{id}', fn () => app(SavedFilterResourceHandler::class)->update(app(ResourceRegistry::class)->get('saved-filters'), request()->route('id'), request()->all()));
            Route::delete('saved/{id}', function () {
                app(SavedFilterResourceHandler::class)->destroy(app(ResourceRegistry::class)->get('saved-filters'), request()->route('id'));

                return response()->noContent();
            });
        });
        $this->getJson('tenant/host/saved?filter[resource]=books')->assertNotFound();
        $this->getJson("tenant/host/saved/{$id}")->assertNotFound();
        $this->putJson("tenant/host/saved/{$id}", [])->assertNotFound();
        $this->deleteJson("tenant/host/saved/{$id}")->assertNotFound();
        $this->getJson("operator/host/saved/{$id}")->assertForbidden();
        Gate::define('entitlement:os.operate', fn () => true);
        $this->getJson("operator/host/saved/{$id}")->assertOk();
        $this->app->bind(ResourceAccessGate::class, fn () => new class implements ResourceAccessGate
        {
            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $definition->key !== 'books';
            }
        });
        $this->getJson('operator/host/saved?filter[resource]=books')->assertForbidden();
        $this->getJson("operator/host/saved/{$id}")->assertForbidden();
        $this->putJson("operator/host/saved/{$id}", [])->assertForbidden();
        $this->deleteJson("operator/host/saved/{$id}")->assertForbidden();
    }

    public function test_declared_stream_vocabulary_supports_saved_views_without_a_filter_query(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'stream', backing: DeclaredStreamFilters::class, data: ResourceFilterData::class, frame: true, readOnly: true));
        $this->assertFalse(DataFilter::registry()->has('stream'));
        DataFilter::options('stream-counts', fn (?string $search) => [['value' => '1', 'label' => $search ?? 'Stream']]);
        $this->getJson('frame/resources/stream/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', 'saved-filters')->assertJsonPath('data.properties.count.x-filter.optionsRef', 'stream-counts');
        $this->getJson('frame/resources/stream/filters/options/stream-counts?search=streamed')->assertOk()->assertJsonPath('data.0.label', 'streamed');
        $id = $this->create('stream');
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.resource', 'stream')
            ->assertJsonPath('data.query_parameters.filter.count', '12');
        $this->putJson("frame/resources/saved-filters/records/{$id}", ['name' => 'Updated stream view', 'query_parameters' => ['filter' => ['count' => 0], 'sort' => '-count']])
            ->assertOk()->assertJsonPath('data.query_parameters.filter.count', 0);
        $this->getJson('frame/resources/saved-filters?filter[resource]=stream')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.query_parameters.sort', '-count');
        foreach ([['filter' => ['unknown' => 1]], ['filter' => ['createdAt' => 1]], ['sort' => 'unknown'], ['include' => 'unknown'], ['limit' => 'unbounded']] as $invalid) {
            $this->postJson('frame/resources/saved-filters', $this->payload('stream', ['query_parameters' => $invalid]))->assertUnprocessable();
        }
        $this->postJson('frame/resources/saved-filters', $this->payload('stream', ['query_parameters' => ['filterVariant' => 'unknown']]))->assertNotFound();
        $this->getJson('frame/resources/stream/filters/options/paper-counts')->assertNotFound();
        $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertNoContent();
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertNotFound();
        $this->assertFalse(DataFilter::registry()->has('stream'));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'unsupported', backing: EmptyStreamFilters::class, data: ResourceFilterData::class, frame: true, readOnly: true));
        $this->getJson('frame/resources/unsupported/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', null);
        $this->postJson('frame/resources/saved-filters', $this->payload('unsupported'))->assertNotFound();
    }

    public function test_realm_mounts_gate_the_target_in_addition_to_the_saved_filter_resource(): void
    {
        app(ParticleResourceRegistry::class)->loadRealmMap(['tenant' => ['papers', 'saved-filters'], 'operator' => ['books', 'saved-filters']]);
        Route::prefix('{realm}')->group(function (): void {
            ResourceRoutes::filters(at: 'books', names: 'realmed-books', defaults: ['resource' => 'books']);

            Route::post('saved', function (Request $request) {
                return app(SavedFilterResourceHandler::class)->store(app(ResourceRegistry::class)->get('saved-filters'), $request->all());
            });
        });
        $this->postJson('tenant/saved', $this->payload('books'))->assertNotFound();
        $this->postJson('tenant/books/filters', [])->assertNotFound();
        $this->getJson('tenant/books/filters/schema')->assertForbidden();
        $this->postJson('operator/saved', $this->payload('books'))->assertForbidden();
        Gate::define('entitlement:os.operate', fn () => true);
        $this->postJson('operator/saved', $this->payload('books'))->assertOk();
        $this->getJson('operator/books/filters/schema')->assertOk();
    }

    public function test_data_filter_only_targets_cannot_gain_saved_metadata_or_mutations(): void
    {
        DataFilter::registry()->registerDefinition(new FilterDefinition('query-only', ResourceFilterData::class, ResourceFilterQuery::class, FilterRecord::class));
        $this->getJson('frame/resources/query-only/filters/schema')->assertNotFound();
        $this->getJson('frame/resources/query-only/filters/variants')->assertNotFound();
        $this->postJson('frame/resources/saved-filters', $this->payload('query-only'))->assertNotFound();
        $saved = SavedFilter::create(['name' => 'Unexposed', 'resource' => 'query-only', 'query_parameters' => [], 'owner_type' => $this->actor(1)->getMorphClass(), 'owner_id' => 1]);
        $this->getJson('frame/resources/saved-filters/records/'.$saved->id)->assertNotFound();
        $this->putJson('frame/resources/saved-filters/records/'.$saved->id, ['name' => 'Changed'])->assertNotFound();
        $this->deleteJson('frame/resources/saved-filters/records/'.$saved->id)->assertNotFound();
        $this->assertSame('Unexposed', $saved->fresh()->name);
    }

    public function test_declared_nonframe_targets_cannot_gain_saved_metadata(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'consumer', backing: FilterRecord::class, data: ResourceFilterData::class, frame: false));
        DataFilter::registry()->registerDefinition(new FilterDefinition('consumer', ResourceFilterData::class, ResourceFilterQuery::class, FilterRecord::class));
        $this->getJson('frame/resources/consumer/filters/schema')->assertNotFound();
        $this->postJson('frame/resources/saved-filters', $this->payload('consumer'))->assertNotFound();
        $this->getJson('frame/resources/papers/filters/schema')->assertOk();
    }
}

class FilterRecord extends Model {}
class ResourceFilterQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        throw new \RuntimeException('Saved validation must not execute a target query, including streams.');
    }
}
class ResourceFilterData extends Data
{
    public function __construct(#[Filterable(Exact::class, options: 'paper-counts')] public int $count) {}
}
class DeniedFilterPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }
}
class DeclaredStreamFilters implements DeclaresFilterVocabulary, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        throw new \RuntimeException('Saving a view must never execute its target backing.');
    }

    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('count', options: 'stream-counts')->sortable(), DeclaredFacet::sort('createdAt'));
    }
}

class EmptyStreamFilters extends DeclaredStreamFilters
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of();
    }
}

class VariantFilterData extends Data
{
    public function __construct(#[Filterable(Exact::class, options: 'paper-statuses')] public string $status) {}
}
class DeniedSavedFilterPolicy
{
    public function create(User $user): bool
    {
        return false;
    }
}
