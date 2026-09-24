<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Contracts\ResourceModelResolver;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class FilterVariantExecutionTest extends TestCase
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
        Schema::create('variant_records', function (Blueprint $table): void {
            $table->id();
            $table->integer('owner_id');
            $table->string('title');
            $table->string('status');
            $table->boolean('enabled');
        });
        $this->actingAs((new User)->forceFill(['id' => 1]));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'variant-records', backing: VariantRecord::class, data: VariantRowData::class,
            frame: true, readOnly: true, ));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('variant-records', CanonicalVariantFilters::class, OwnerVariantQuery::class, VariantRecord::class));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('active-records', SelectedVariantFilters::class, EnabledVariantQuery::class, VariantRecord::class, 'variant-records'));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('other-records', SelectedVariantFilters::class, EnabledVariantQuery::class, VariantRecord::class, 'another-target'));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('wrong-model', SelectedVariantFilters::class, EnabledVariantQuery::class, User::class, 'variant-records'));
        foreach ([
            ['owner_id' => 1, 'title' => 'Owned open', 'status' => 'open', 'enabled' => true],
            ['owner_id' => 1, 'title' => 'Owned closed', 'status' => 'closed', 'enabled' => true],
            ['owner_id' => 2, 'title' => 'Foreign open', 'status' => 'open', 'enabled' => true],
            ['owner_id' => 1, 'title' => 'Disabled open', 'status' => 'open', 'enabled' => false],
        ] as $row) {
            VariantRecord::create($row);
        }
    }

    public function test_owner_scope_serves_metadata_and_saved_views_without_class_wide_read_permission(): void
    {
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        $this->assertFalse(Gate::allows('viewAny', VariantRecord::class));
        $this->assertSame('sqlite', VariantRecord::resolveConnection()->getDriverName());
        $this->assertSame(':memory:', VariantRecord::resolveConnection()->getDatabaseName());
        $this->getJson('frame/resources/variant-records')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('frame/resources/variant-records/filters/schema')->assertOk()
            ->assertJsonPath('data.properties.title.x-filter.operator', 'exact');
        $this->getJson('frame/resources/variant-records/filters/variants')->assertOk()->assertJsonCount(2, 'data.variants');
        $this->getJson('frame/resources/variant-records/filters/active-records/schema')->assertOk()
            ->assertJsonPath('data.properties.status.x-filter.optionsRef', 'variant-statuses');
        $params = ['filterVariant' => 'active-records', 'filter' => ['status' => 'open']];
        $id = $this->postJson('frame/resources/saved-filters', [
            'resource' => 'variant-records', 'name' => 'Owned open', 'query_parameters' => $params,
        ])->assertOk()->json('data.id');
        $this->getJson('frame/resources/saved-filters/records/'.$id)->assertOk()
            ->assertJsonPath('data.query_parameters', $params);
        $this->putJson('frame/resources/saved-filters/records/'.$id, ['name' => 'Renamed'])->assertOk();
        $this->getJson('frame/resources/variant-records?'.http_build_query($params))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
        $this->deleteJson('frame/resources/saved-filters/records/'.$id)->assertNoContent();
        $this->getJson('frame/resources/saved-filters/records/'.$id)->assertNotFound();
        $this->actingAs((new User)->forceFill(['id' => 2]));
        $this->getJson('frame/resources/variant-records?'.http_build_query($params))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Foreign open');
        foreach (['other-records', 'wrong-model'] as $variant) {
            $this->getJson('frame/resources/variant-records/filters/'.$variant.'/schema')->assertNotFound();
        }
    }

    public function test_scoped_metadata_keeps_option_provider_ownership_and_search(): void
    {
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        DataFilter::options('variant-statuses', fn (?string $search) => VariantRecord::query()
            ->where('owner_id', auth()->id())->where('title', 'like', '%'.$search.'%')->orderBy('id')->get()
            ->map(fn (VariantRecord $row) => ['value' => $row->getKey(), 'label' => $row->title])->all());
        DataFilter::options('unreferenced', fn () => throw new \RuntimeException('Unreferenced source executed'));
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses?search=closed')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'Owned closed');
        $this->getJson('frame/resources/variant-records/filters/options/unreferenced')->assertNotFound();
        $this->actingAs((new User)->forceFill(['id' => 2]));
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses?search=open')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'Foreign open');
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses?search=closed')->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_model_backed_candidate_cannot_borrow_its_targets_owner_scope(): void
    {
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'active-records', backing: VariantRecord::class, data: SelectedVariantFilters::class,
            frame: false, readOnly: true,
        ));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('active-records', SelectedVariantFilters::class,
            UnscopedVariantQuery::class, VariantRecord::class, 'variant-records'));
        DataFilter::options('variant-statuses', fn () => throw new \RuntimeException('Denied candidate options executed'));
        $this->getJson('frame/resources/variant-records/filters/schema')->assertOk();
        $this->getJson('frame/resources/variant-records/filters/variants')->assertOk()->assertJsonCount(1, 'data.variants');
        $this->getJson('frame/resources/variant-records/filters/active-records/schema')->assertForbidden();
        $this->getJson('frame/resources/variant-records?filterVariant=active-records')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses')->assertNotFound();
    }

    public function test_a_boundary_that_admits_no_row_grants_no_metadata(): void
    {
        // `1 = 0` is the estate's fail-closed row scope for an actor holding no view token. Structurally
        // it is a predicate; it is a refusal, and must not stand in for the ownership scope above.
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        DataFilter::registry()->registerDefinition(new ResourceDefinition('variant-records', CanonicalVariantFilters::class,
            NoRowVariantQuery::class, VariantRecord::class));
        DataFilter::options('variant-statuses', fn () => throw new \RuntimeException('Refused options executed'));
        $this->getJson('frame/resources/variant-records/filters/schema')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/variants')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses')->assertForbidden();
    }

    public function test_scope_permission_does_not_transfer_to_another_filter_model(): void
    {
        Gate::policy(User::class, DeniedVariantModelPolicy::class);
        DataFilter::registry()->registerDefinition(new ResourceDefinition('variant-records', CanonicalVariantFilters::class,
            OwnerVariantQuery::class, User::class));
        $this->getJson('frame/resources/variant-records/filters/schema')->assertForbidden();
    }

    public function test_selected_variant_executes_its_facet_and_keeps_both_query_scopes(): void
    {
        $this->getJson('frame/resources/variant-records?filterVariant=active-records&filter[status]=open')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
        $this->getJson('frame/resources/variant-records?filterVariant=active-records&filter[status]=closed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned closed');
        $this->getJson('frame/resources/variant-records?filter[title]=Owned%20open')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
    }

    public function test_nonframe_consumer_lists_keep_declared_variant_scopes_and_authorization(): void
    {
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'variant-records', backing: VariantRecord::class, data: VariantRowData::class,
            frame: false, readOnly: true,
        ));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'active-records', backing: VariantPolicyBacking::class, data: SelectedVariantFilters::class,
            policy: 'consumer.variant', frame: false, readOnly: true,
        ));
        Gate::define('consumer.variant', fn () => true);
        Particle::mount('consumer-records', 'variant-records')->only(['index'])->hookEvents(false);
        $this->getJson('consumer-records?filterVariant=active-records&filter[status]=open')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
        $this->getJson('consumer-records?filterVariant=active-records&filter[unknown]=x')->assertStatus(400);
        $this->getJson('frame/resources/variant-records/filters/schema')->assertNotFound();
        $this->getJson('consumer-records/filters/schema')->assertNotFound();
        Gate::define('consumer.variant', fn () => false);
        $this->getJson('consumer-records?filterVariant=active-records&filter[status]=open')->assertForbidden();
        $this->getJson('consumer-records?filter[title]=Owned%20open')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_scope_composition_preserves_the_request_and_does_not_limit_target_ids(): void
    {
        $request = Request::create('/records', 'GET', ['filterVariant' => 'active-records', 'filter' => ['status' => 'closed'],
            'sort' => '-status', 'limit' => 1, 'perPage' => 1, 'page' => 2, 'savedFilter' => 'stale', 'saved_filter' => 'stale']);
        $request->setUserResolver(fn () => auth()->user());
        $before = $request->query();
        $query = app(ParticleListQuery::class)->forList(app(ParticleResourceRegistry::class)->get('variant-records'), (array) $request->input('filter', []), $request);
        $this->assertSame(['Owned closed'], $query->pluck('title')->all());
        $this->assertSame($before, $request->query());
    }

    public function test_variant_scope_ids_ignore_canonical_pagination_defaults(): void
    {
        DataFilter::registry()->registerDefinition(new ResourceDefinition('variant-records', CanonicalVariantFilters::class, LimitedOwnerVariantQuery::class, VariantRecord::class));
        $this->getJson('frame/resources/variant-records?filterVariant=active-records&filter[status]=open')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
    }

    public function test_variant_only_query_saves_reloads_and_applies_without_canonical_fallback(): void
    {
        $params = ['filterVariant' => 'active-records', 'filter' => ['status' => 'open']];
        $id = $this->postJson('frame/resources/saved-filters', [
            'resource' => 'variant-records', 'name' => 'Open', 'query_parameters' => $params,
        ])->assertSuccessful()->json('data.id');
        $stored = $this->getJson('frame/resources/saved-filters/records/'.$id)->assertOk()->json('data.query_parameters');
        $this->assertSame($params, $stored);
        $this->getJson('frame/resources/variant-records?'.http_build_query($stored))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Owned open');
        $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Invalid', 'query_parameters' => [
            'filterVariant' => 'active-records', 'filter' => ['title' => 'Owned open'],
        ]])->assertUnprocessable();
    }

    public function test_invalid_cross_target_and_cross_model_variants_fail_on_reads_and_saves(): void
    {
        foreach (['unknown', 'other-records', 'wrong-model'] as $variant) {
            $this->getJson('frame/resources/variant-records?filterVariant='.$variant)->assertNotFound();
            $this->getJson('frame/resources/variant-records/filters/'.$variant.'/schema')->assertNotFound();
            $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Invalid',
                'query_parameters' => ['filterVariant' => $variant]])->assertNotFound();
        }
        $this->getJson('frame/resources/variant-records?filterVariant[]=active-records')->assertUnprocessable();
    }

    public function test_listed_owned_views_do_not_offer_mutations_when_their_stored_variant_is_denied(): void
    {
        $canonical = $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Canonical',
            'query_parameters' => ['filter' => ['title' => 'Owned open']]])->assertSuccessful()->json('data.id');
        $variant = $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Variant',
            'query_parameters' => ['filterVariant' => 'active-records', 'filter' => ['status' => 'open']]])->assertSuccessful()->json('data.id');
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'active-records', backing: VariantPolicyBacking::class, data: SelectedVariantFilters::class,
            policy: 'variant.read', frame: false, readOnly: true,
        ));
        Gate::define('variant.read', fn (User $user): bool => false);
        $rows = $this->getJson('frame/resources/saved-filters?filter[resource]=variant-records')->assertOk()->json('data');
        $byId = array_column($rows, null, 'id');
        $this->assertFalse($byId[$variant]['can']['delete']);
        $this->assertFalse($byId[$variant]['can']['update']);
        $this->assertTrue($byId[$canonical]['can']['delete']);
        $this->deleteJson('frame/resources/saved-filters/records/'.$variant)->assertForbidden();
        $this->deleteJson('frame/resources/saved-filters/records/'.$canonical)->assertNoContent();
    }

    public function test_privileged_policy_override_cannot_advertise_an_unavailable_stored_variant(): void
    {
        $canonical = $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Canonical', 'visibility' => 'shared'])
            ->assertSuccessful()->json('data.id');
        $variant = $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Variant',
            'query_parameters' => ['filterVariant' => 'active-records']])->assertSuccessful()->json('data.id');
        DataFilter::registry()->registerDefinition(new ResourceDefinition('active-records', SelectedVariantFilters::class, EnabledVariantQuery::class, User::class, 'variant-records'));
        Gate::before(fn () => true);
        $rows = array_column($this->getJson('frame/resources/saved-filters?filter[resource]=variant-records')->assertOk()->json('data'), null, 'id');
        $this->assertFalse($rows[$variant]['can']['delete']);
        $this->assertFalse($rows[$variant]['can']['update']);
        $this->assertTrue($rows[$canonical]['can']['delete']);
        $this->deleteJson('frame/resources/saved-filters/records/'.$variant)->assertNotFound();
        $this->actingAs((new User)->forceFill(['id' => 2]));
        $this->getJson('frame/resources/saved-filters?filter[resource]=variant-records')->assertOk()
            ->assertJsonPath('data.0.id', $canonical)->assertJsonPath('data.0.can.delete', false);
        $this->deleteJson('frame/resources/saved-filters/records/'.$canonical)->assertNotFound();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        $this->deleteJson('frame/resources/saved-filters/records/'.$canonical)->assertNoContent();
    }

    public function test_nonframed_candidate_still_requires_its_declared_realm(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'active-records', backing: VariantRecord::class, data: SelectedVariantFilters::class,
            frame: false, readOnly: true,
        ), ['operator']);
        app(ParticleResourceRegistry::class)->loadRealmMap(['tenant' => ['variant-records', 'saved-filters'],
            'operator' => ['variant-records', 'saved-filters', 'active-records']]);
        Route::get('{realm}/catalog/{resource}', fn (Request $request) => app(FrameResourceController::class)->index($request, (string) $request->route('resource')));
        $this->getJson('frame/resources/variant-records?filterVariant=active-records')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/active-records/schema')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/variants')->assertOk()->assertJsonCount(1, 'data.variants');
        $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Denied',
            'query_parameters' => ['filterVariant' => 'active-records']])->assertForbidden();
        Gate::define('entitlement:os.operate', fn () => true);
        $this->getJson('tenant/catalog/variant-records?filterVariant=active-records')->assertNotFound();
        $this->getJson('operator/catalog/variant-records?filterVariant=active-records&filter[status]=open')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_vocabulary_only_and_unresolved_candidates_are_ineligible_variants(): void
    {
        DataFilter::registry()->registerDefinition(new ResourceDefinition('vocabulary-only', SelectedVariantFilters::class, \stdClass::class, VariantRecord::class, 'variant-records'));
        DataFilter::registry()->registerDefinition(new ResourceDefinition('unresolved-variant', SelectedVariantFilters::class, EnabledVariantQuery::class, null, 'variant-records'));
        $this->app->bind(ResourceModelResolver::class, fn () => new class implements ResourceModelResolver
        {
            public function resolveModel(string $resource): ?string
            {
                return null;
            }
        });
        foreach (['vocabulary-only', 'unresolved-variant'] as $variant) {
            $this->getJson('frame/resources/variant-records?filterVariant='.$variant)->assertNotFound();
            $this->getJson('frame/resources/variant-records/filters/'.$variant.'/schema')->assertNotFound();
            $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Ineligible',
                'query_parameters' => ['filterVariant' => $variant]])->assertNotFound();
        }
        $this->getJson('frame/resources/variant-records/filters/variants')->assertOk()->assertJsonCount(2, 'data.variants');
    }

    public function test_a_selected_variant_cannot_bypass_its_declared_read_policy(): void
    {
        Gate::policy(VariantRecord::class, DeniedVariantModelPolicy::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'active-records', backing: VariantPolicyBacking::class, data: SelectedVariantFilters::class,
            policy: 'variant.read', frame: false, readOnly: true,
        ));
        Gate::define('variant.read', fn (User $user): bool => false);
        DataFilter::options('variant-statuses', fn () => throw new \RuntimeException('Denied variant options must not execute'));
        $this->getJson('frame/resources/variant-records/filters/options/variant-statuses')->assertNotFound();
        $this->getJson('frame/resources/variant-records/filters/variants')->assertOk()
            ->assertJsonCount(1, 'data.variants')->assertJsonPath('data.variants.0.key', 'variant-records');
        $this->getJson('frame/resources/variant-records?filterVariant=active-records')->assertForbidden();
        $this->getJson('frame/resources/variant-records/filters/active-records/schema')->assertForbidden();
        $this->postJson('frame/resources/saved-filters', ['resource' => 'variant-records', 'name' => 'Denied',
            'query_parameters' => ['filterVariant' => 'active-records']])->assertForbidden();
        $this->getJson('frame/resources/variant-records')->assertOk()->assertJsonCount(3, 'data');
    }
}

class VariantRecord extends Model
{
    protected $table = 'variant_records';

    protected $guarded = [];

    public $timestamps = false;
}

class VariantRowData extends Data
{
    public function __construct(public int $id, public string $title) {}
}

class CanonicalVariantFilters extends Data
{
    public function __construct(#[Filterable(Exact::class)] public string $title) {}
}

class SelectedVariantFilters extends Data
{
    public function __construct(#[Filterable(Exact::class, options: 'variant-statuses')] #[Sortable] public string $status) {}
}

class OwnerVariantQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return VariantRecord::query()->where('owner_id', $request->user()->getAuthIdentifier())
            ->when($request->query('limit'), fn ($query, $limit) => $query->limit((int) $limit))
            ->when($request->query('savedFilter') ?? $request->query('saved_filter'), fn ($query) => $query->whereRaw('1 = 0'));
    }
}

class EnabledVariantQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return VariantRecord::query()->where('enabled', true);
    }
}

class VariantPolicyBacking implements ResourceBacking {}

class LimitedOwnerVariantQuery extends OwnerVariantQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return parent::baseQuery($request)->limit(1)->offset(1);
    }
}

class DeniedVariantModelPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }
}

class UnscopedVariantQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return VariantRecord::query();
    }
}

class NoRowVariantQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return VariantRecord::query()->whereRaw('1 = 0');
    }
}
