<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceFilterProvider;
use Schemastud\Frame\Contracts\ResourceFilterValidator;
use Schemastud\Frame\Data\FilterOptionData;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantData;
use Schemastud\Frame\Data\FilterVariantsData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Routing\ResourceRoutes;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\TestCase;

class FrameProviderAuthorityTest extends TestCase
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
        Schema::create('provider_records', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        ProviderRecord::create(['title' => 'Matched']);
        ProviderRecord::create(['title' => 'Different']);
        $this->actingAs((new User)->forceFill(['id' => 1]));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'provider-records', backing: ProviderRecord::class, data: ProviderRowData::class,
            frame: true, readOnly: true, handler: ProviderRecordHandler::class,
            filterProvider: AuthoritativeProvider::class,
        ));
        Particle::filters('provider-records', at: 'provider-records');
        AuthoritativeProvider::$calls = [];
        AuthoritativeProvider::$savesOnlyForVariant = false;
    }

    public function test_all_filter_reads_use_the_declared_provider_on_both_mounts(): void
    {
        foreach (['schema', 'focused/schema', 'variants', 'options/custom-titles?search=Ada%20Lovelace'] as $suffix) {
            $canonical = $this->getJson('frame/resources/provider-records/filters/'.$suffix)->assertOk();
            $retained = $this->getJson('provider-records/filters/'.$suffix)->assertOk();
            $this->assertSame($canonical->json(), $retained->json());
        }
        $this->assertContains(['schema', 'provider-records', 'focused'], AuthoritativeProvider::$calls);
        $this->assertContains(['options', 'provider-records', 'custom-titles', 'Ada Lovelace'], AuthoritativeProvider::$calls);
        $this->assertContains(['variants', 'provider-records'], AuthoritativeProvider::$calls);
        $this->getJson('frame/resources/provider-records/filters/schema')->assertOk()
            ->assertJsonPath('data.properties.customTitle.x-filter.control', 'select')
            ->assertJsonPath('data.properties.customMode.x-filter.options.0.value', 'exact');
        $this->getJson('frame/resources/provider-records/filters/options/custom-titles')->assertOk()
            ->assertJsonPath('data.0.value', ProviderRecord::firstOrFail()->title);
    }

    public function test_denial_precedes_every_custom_provider_call_on_both_mounts(): void
    {
        $this->app->bind(ResourceAccessGate::class, fn () => new class implements ResourceAccessGate
        {
            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $definition->key !== 'provider-records';
            }
        });
        foreach (['schema', 'focused/schema', 'variants', 'options/custom-titles?search[]=bad'] as $suffix) {
            $this->getJson('frame/resources/provider-records/filters/'.$suffix)->assertForbidden();
            $this->getJson('provider-records/filters/'.$suffix)->assertForbidden();
        }
        $this->assertSame([], AuthoritativeProvider::$calls);
    }

    public function test_custom_validation_persists_reloads_and_applies_without_a_data_filter_registration(): void
    {
        $this->assertNull(DataFilter::tryResource('provider-records'));
        $params = ['filter' => ['customTitle' => 'Matched', 'customMode' => 'exact']];
        foreach (['frame/resources/saved-filters', 'provider-records/filters'] as $url) {
            $id = $this->postJson($url, ['resource' => 'provider-records', 'name' => 'Named', 'query_parameters' => $params])
                ->assertSuccessful()->json('data.id');
            $stored = $this->getJson('frame/resources/saved-filters/records/'.$id)->assertOk()->json('data.query_parameters');
            $this->assertSame($params, $stored);
            $this->getJson('frame/resources/provider-records?'.http_build_query($stored))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Matched');
            $this->postJson($url, ['resource' => 'provider-records', 'name' => 'Invalid', 'query_parameters' => ['filter' => ['count' => 4]]])
                ->assertUnprocessable();
        }
        $this->assertSame(2, SavedFilter::count());
        $this->assertContains(['validate', 'provider-records', $params], AuthoritativeProvider::$calls);
    }

    public function test_variant_specific_saved_capability_survives_save_reload_update_and_list(): void
    {
        AuthoritativeProvider::$savesOnlyForVariant = true;
        $this->getJson('frame/resources/provider-records/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', null);
        $this->getJson('provider-records/filters/focused/schema')->assertOk()->assertJsonPath('savedViewsResource', 'saved-filters');
        $params = ['filter' => ['customTitle' => 'Matched', 'customMode' => 'exact'], 'filterVariant' => 'focused'];
        $id = $this->postJson('frame/resources/saved-filters', ['resource' => 'provider-records', 'name' => 'Focused', 'query_parameters' => $params])
            ->assertSuccessful()->json('data.id');
        $this->getJson('provider-records/filters/'.$id)->assertOk()->assertJsonPath('data.query_parameters', $params);
        $this->putJson('frame/resources/saved-filters/records/'.$id, ['name' => 'Renamed'])->assertOk()
            ->assertJsonPath('data.query_parameters', $params);
        $this->getJson('frame/resources/saved-filters?filter[resource]=provider-records&filterVariant=focused')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('provider-records/filters?filterVariant=focused')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('frame/resources/provider-records?'.http_build_query($params))->assertOk()->assertJsonCount(1, 'data');
        unset($params['filterVariant']);
        $this->postJson('provider-records/filters', ['name' => 'Unavailable', 'query_parameters' => $params])->assertNotFound();
        $this->deleteJson('provider-records/filters/'.$id)->assertNoContent();
    }

    public function test_saved_view_discovery_respects_the_saved_resource_reach_and_realm(): void
    {
        $this->app->bind(ResourceAccessGate::class, fn () => new class implements ResourceAccessGate
        {
            public function allowsResource(ResourceDefinition $definition): bool
            {
                return $definition->key !== 'saved-filters';
            }
        });
        $this->getJson('frame/resources/provider-records/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', null);
        $this->getJson('provider-records/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', null);
        $this->getJson('frame/resources/saved-filters?filter[resource]=provider-records')->assertForbidden();
    }

    public function test_explicit_realm_cannot_borrow_saved_resource_membership_from_another_realm(): void
    {
        $this->assertInstanceOf(RealmEntitlementResourceGate::class, app(ResourceAccessGate::class));
        app(ParticleResourceRegistry::class)->loadRealmMap(['tenant' => ['provider-records'], 'operator' => ['saved-filters']]);
        config(['beam.core.realm_gates' => []]);
        Route::prefix('{realm}')->group(fn () => ResourceRoutes::filters(at: 'catalog/{resource}', names: 'provider-probe'));
        $this->getJson('tenant/catalog/provider-records/filters/schema')->assertOk()->assertJsonPath('savedViewsResource', null);
    }

    public function test_saved_records_publish_permissions_for_each_owner_and_visibility(): void
    {
        $ids = [];
        foreach (['private', 'shared', 'public'] as $visibility) {
            $ids[] = $this->postJson('frame/resources/saved-filters', ['resource' => 'provider-records', 'name' => $visibility,
                'visibility' => $visibility, 'query_parameters' => ['filter' => ['customTitle' => 'Matched', 'customMode' => 'exact']]])
                ->assertSuccessful()->assertJsonPath('data.can.delete', true)->json('data.id');
        }
        $this->actingAs((new User)->forceFill(['id' => 2]));
        $this->getJson('frame/resources/saved-filters?filter[resource]=provider-records')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.can.delete', false)->assertJsonPath('data.1.can.update', false);
        $this->getJson('provider-records/filters/'.$ids[1])->assertOk()->assertJsonPath('data.can.delete', false);
        $this->deleteJson('frame/resources/saved-filters/records/'.$ids[1])->assertNotFound();
        $this->deleteJson('provider-records/filters/'.$ids[2])->assertNotFound();
        Gate::policy(SavedFilter::class, ProviderSavedCreateDeniedPolicy::class);
        $this->getJson('frame/resources/provider-records/filters/schema')->assertOk()
            ->assertJsonPath('savedViewsResource', 'saved-filters')->assertJsonPath('savedViewsCan.create', false);
        $this->getJson('frame/resources/saved-filters?filter[resource]=provider-records')->assertOk();
    }
}

class ProviderRecord extends Model
{
    protected $table = 'provider_records';

    protected $guarded = [];

    public $timestamps = false;
}

class ProviderRowData extends Data
{
    public function __construct(public int $id, public string $title) {}
}

class AuthoritativeProvider implements ResourceFilterProvider, ResourceFilterValidator
{
    public static array $calls = [];

    public static bool $savesOnlyForVariant = false;

    public function schema(ResourceDefinition $resource, ?string $variant = null): FilterSchemaResponseData
    {
        self::$calls[] = ['schema', $resource->key, $variant];
        abort_if($variant !== null && $variant !== 'focused', 404);

        return new FilterSchemaResponseData(['type' => 'object', 'properties' => ['customTitle' => [
            'x-filter' => ['name' => 'customTitle', 'operator' => 'exact', 'control' => 'select', 'optionsRef' => 'custom-titles'],
        ], 'customMode' => ['x-filter' => ['name' => 'customMode', 'operator' => 'exact', 'control' => 'select', 'options' => [['value' => 'exact', 'label' => 'Exact']]]]], 'variant' => $variant], self::$savesOnlyForVariant && $variant === null ? null : 'saved-filters');
    }

    public function options(ResourceDefinition $resource, string $ref, ?string $search = null): FilterOptionsResponseData
    {
        self::$calls[] = ['options', $resource->key, $ref, $search];
        abort_unless($ref === 'custom-titles', 404);

        return new FilterOptionsResponseData([new FilterOptionData('Matched', $search ?? 'Matched')]);
    }

    public function variants(ResourceDefinition $resource): FilterVariantsResponseData
    {
        self::$calls[] = ['variants', $resource->key];

        return new FilterVariantsResponseData(new FilterVariantsData($resource->key, [new FilterVariantData('focused', $resource->key, false, false)]));
    }

    public function validate(ResourceDefinition $resource, array $parameters): array
    {
        self::$calls[] = ['validate', $resource->key, $parameters];

        return Validator::make($parameters, ['filter' => ['required', 'array:customTitle,customMode'],
            'filter.customTitle' => ['required', 'string'], 'filter.customMode' => ['required', 'in:exact'], 'filterVariant' => ['sometimes', 'in:focused']])->validate();
    }
}

class ProviderRecordHandler implements FrameResourceHandler
{
    public function index(ResourceDefinition $definition, array $params): array
    {
        $query = ProviderRecord::query();
        if (isset($params['filter'])) {
            $params = app(AuthoritativeProvider::class)->validate($definition, $params);
            $query->where('title', $params['filter']['customTitle']);
        }

        return $query->get()->toArray();
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        return ProviderRecord::findOrFail($id)->toArray();
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        throw new \LogicException('Read only');
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        throw new \LogicException('Read only');
    }

    public function destroy(ResourceDefinition $definition, string $id): void
    {
        throw new \LogicException('Read only');
    }
}

class ProviderSavedCreateDeniedPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SavedFilter $saved): bool
    {
        return false;
    }

    public function delete(User $user, SavedFilter $saved): bool
    {
        return false;
    }
}
