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
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\BeamResourceFilterProvider;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\SavedFilterResourceHandler;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
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
            Particle::filters($key, at: $key);
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

    public function test_canonical_and_legacy_routes_create_read_update_delete_the_same_record(): void
    {
        $id = $this->create();
        $this->getJson('papers/filters')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.query_parameters.filter.count', 12);
        $this->putJson("papers/filters/{$id}", ['name' => 'Updated', 'query_parameters' => ['filter' => ['count' => '7']]])->assertOk();
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.query_parameters.filter.count', 7);
        $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertNoContent();
        $this->getJson("papers/filters/{$id}")->assertNotFound();
        $legacy = $this->postJson('papers/filters', ['name' => 'Legacy', 'query_parameters' => []])->assertCreated()->json('data.id');
        $this->putJson("frame/resources/saved-filters/records/{$legacy}", ['name' => 'Canonical'])->assertOk();
        $this->getJson("papers/filters/{$legacy}")->assertOk()->assertJsonPath('data.name', 'Canonical');
        $this->deleteJson("papers/filters/{$legacy}")->assertNoContent();
    }

    public function test_legacy_create_ignores_a_body_target_and_uses_the_mounted_resource(): void
    {
        $id = $this->postJson('papers/filters', $this->payload('books'))->assertCreated()->assertJsonPath('data.resource', 'papers')->json('data.id');
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.resource', 'papers');
        $this->getJson('books/filters')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_empty_queries_serialize_as_objects_on_both_paths_and_keep_legacy_metadata(): void
    {
        foreach (['frame/resources/saved-filters', 'papers/filters'] as $path) {
            $created = $this->postJson($path, ['name' => 'Empty', 'resource' => 'papers'])->assertSuccessful();
            $id = $created->json('data.id');
            $this->assertInstanceOf(\stdClass::class, json_decode($created->getContent())->data->query_parameters);
            $this->assertSame([], SavedFilter::findOrFail($id)->query_parameters);
            foreach (["frame/resources/saved-filters/records/{$id}", "papers/filters/{$id}"] as $recordPath) {
                $read = $this->getJson($recordPath)->assertOk();
                $this->assertInstanceOf(\stdClass::class, json_decode($read->getContent())->data->query_parameters);
                $updated = $this->putJson($recordPath, ['name' => 'Still empty'])->assertOk();
                $this->assertInstanceOf(\stdClass::class, json_decode($updated->getContent())->data->query_parameters);
            }
            $legacy = $this->getJson("papers/filters/{$id}")->assertOk();
            foreach (['owner_type', 'owner_id', 'context_type', 'context_id', 'created_at', 'updated_at'] as $field) {
                $this->assertSame(SavedFilter::findOrFail($id)->toArray()[$field], $legacy->json('data.'.$field));
            }
        }
        foreach (['frame/resources/saved-filters?filter[resource]=papers', 'papers/filters'] as $path) {
            $list = $this->getJson($path)->assertOk();
            $this->assertInstanceOf(\stdClass::class, json_decode($list->getContent())->data[0]->query_parameters);
        }
        SavedFilter::findOrFail($id)->update(['owner_type' => null, 'owner_id' => null, 'visibility' => 'public']);
        $this->getJson("frame/resources/saved-filters/records/{$id}")->assertOk()->assertJsonPath('data.visibility', 'public');
        $this->getJson("papers/filters/{$id}")->assertOk()->assertJsonPath('data.owner_type', null)->assertJsonPath('data.owner_id', null);
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

    public function test_visibility_and_mutation_ownership_survive_both_entry_points(): void
    {
        $private = $this->create();
        $shared = $this->create(extra: ['visibility' => 'shared']);
        $public = $this->create(extra: ['visibility' => 'public']);
        $this->actingAs($this->actor(2));
        $this->getJson('frame/resources/saved-filters?filter[resource]=papers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("frame/resources/saved-filters/records/{$private}")->assertNotFound();
        $this->getJson("papers/filters/{$shared}")->assertOk();
        $this->getJson("papers/filters/{$public}")->assertOk();
        foreach ([$private, $shared, $public] as $id) {
            $this->putJson("frame/resources/saved-filters/records/{$id}", [])->assertNotFound();
            $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertNotFound();
            $this->putJson("papers/filters/{$id}", [])->assertNotFound();
            $this->deleteJson("papers/filters/{$id}")->assertNotFound();
        }
    }

    public function test_target_access_precedes_validation_for_canonical_and_legacy_reads_and_writes(): void
    {
        $id = $this->create();
        $this->app->bind(ResourceAccessGate::class, fn () => new class implements ResourceAccessGate
        {
            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $definition->key !== 'papers';
            }
        });
        foreach (['frame/resources/papers/filters/schema', 'papers/filters/schema', 'frame/resources/saved-filters?filter[resource]=papers', "frame/resources/saved-filters/records/{$id}", 'papers/filters'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('frame/resources/saved-filters', ['resource' => 'papers'])->assertForbidden();
        $this->postJson('papers/filters', [])->assertForbidden();
        $this->putJson("frame/resources/saved-filters/records/{$id}", [])->assertForbidden();
        $this->putJson("papers/filters/{$id}", [])->assertForbidden();
        $this->deleteJson("frame/resources/saved-filters/records/{$id}")->assertForbidden();
        $this->deleteJson("papers/filters/{$id}")->assertForbidden();
        $this->create('books');
    }

    public function test_existing_target_policy_is_still_required_after_frame_reach(): void
    {
        $id = $this->create();
        Gate::policy(FilterRecord::class, DeniedFilterPolicy::class);
        $this->postJson('frame/resources/saved-filters', ['resource' => 'papers'])->assertForbidden();
        $this->postJson('papers/filters', [])->assertForbidden();
        $this->getJson('frame/resources/papers/filters/schema')->assertForbidden();
        $this->getJson('papers/filters/schema')->assertForbidden();
        foreach (["frame/resources/saved-filters/records/{$id}", "papers/filters/{$id}"] as $path) {
            $this->getJson($path)->assertForbidden();
            $this->putJson($path, [])->assertForbidden();
            $this->deleteJson($path)->assertForbidden();
        }
    }

    public function test_target_is_immutable_and_invalid_queries_and_values_do_not_persist(): void
    {
        $id = $this->create();
        $this->putJson("frame/resources/saved-filters/records/{$id}", $this->payload('books'))->assertUnprocessable();
        $this->putJson("papers/filters/{$id}", $this->payload('books'))->assertOk()->assertJsonPath('data.resource', 'papers');
        foreach ([['filter' => ['unknown' => 1]], ['filter' => ['count' => 'no-number']], ['filter' => 'bad'], ['sort' => ['nested' => []]], ['include' => 'unknown'], ['limit' => 'bad'], ['unrecognized' => true]] as $query) {
            $this->postJson('frame/resources/saved-filters', $this->payload(extra: ['query_parameters' => $query]))->assertUnprocessable();
            $this->postJson('papers/filters', ['name' => 'Bad', 'query_parameters' => $query])->assertUnprocessable();
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
        $last = $this->postJson('papers/filters', ['name' => 'New default', 'is_default' => true])->assertCreated()->json('data.id');
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
        $this->getJson('papers/filters/options/paper-counts?search=legacy')->assertOk()->assertJsonPath('data.0.label', 'legacy');
        $this->getJson('frame/resources/papers/filters/options/other-secrets')->assertNotFound();
        $this->getJson('papers/filters/options/other-secrets')->assertNotFound();
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
        $this->getJson('papers/filters/recent-papers/schema')->assertOk();
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
            Particle::filters('books', at: 'books', names: 'realmed-books');
            Route::post('saved', function (Request $request) {
                return app(SavedFilterResourceHandler::class)->store(app(ResourceRegistry::class)->get('saved-filters'), $request->all());
            });
        });
        $this->postJson('tenant/saved', $this->payload('books'))->assertNotFound();
        $this->postJson('tenant/books/filters', [])->assertNotFound();
        $this->getJson('tenant/books/filters/schema')->assertNotFound();
        $this->postJson('operator/saved', $this->payload('books'))->assertForbidden();
        Gate::define('entitlement:os.operate', fn () => true);
        $this->postJson('operator/saved', $this->payload('books'))->assertOk();
    }

    public function test_legacy_only_saved_variant_permissions_follow_current_variant_access(): void
    {
        DataFilter::registry()->registerDefinition(new FilterDefinition('legacy-only', ResourceFilterData::class, ResourceFilterQuery::class, FilterRecord::class));
        DataFilter::registry()->registerDefinition(new FilterDefinition('legacy-variant', ResourceFilterData::class, ResourceFilterQuery::class, FilterRecord::class, 'legacy-only'));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'legacy-variant', backing: LegacyVariantPolicyBacking::class, data: ResourceFilterData::class,
            frame: false, readOnly: true, policy: 'legacy.variant',
        ));
        Particle::filters('legacy-only', at: 'legacy-only');
        Gate::define('legacy.variant', fn () => true);
        $canonical = $this->postJson('legacy-only/filters', ['name' => 'Canonical'])->assertCreated()->json('data.id');
        $variant = $this->postJson('legacy-only/filters', ['name' => 'Selected', 'query_parameters' => ['filterVariant' => 'legacy-variant']])
            ->assertCreated()->assertJsonPath('data.can.delete', true)->json('data.id');
        Gate::define('legacy.variant', fn () => false);
        $rows = array_column($this->getJson('legacy-only/filters')->assertOk()->json('data'), null, 'id');
        $this->assertFalse($rows[$variant]['can']['delete']);
        $this->assertTrue($rows[$canonical]['can']['delete']);
        $this->getJson('legacy-only/filters/'.$variant)->assertForbidden();
        $this->deleteJson('legacy-only/filters/'.$variant)->assertForbidden();
        $this->deleteJson('legacy-only/filters/'.$canonical)->assertNoContent();
    }

    public function test_canonical_saved_targets_require_frame_membership_while_legacy_only_targets_keep_working(): void
    {
        DataFilter::registry()->registerDefinition(new FilterDefinition('legacy-only', ResourceFilterData::class, ResourceFilterQuery::class, FilterRecord::class));
        Particle::filters('legacy-only', at: 'legacy-only');
        $this->postJson('frame/resources/saved-filters', $this->payload('legacy-only'))->assertNotFound();
        $id = $this->postJson('legacy-only/filters', ['name' => 'Existing API'])->assertCreated()->json('data.id');
        $this->putJson('legacy-only/filters/'.$id, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.can.delete', true);
        $this->deleteJson('legacy-only/filters/'.$id)->assertNoContent();
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

class LegacyVariantPolicyBacking implements ResourceBacking {}
