<?php

namespace Splicewire\Beam\Tests\Frame;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterDefinition;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ScopedIndexQuery;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\BareGadgetQuery;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\OwnedGadgetQuery;
use Splicewire\Beam\Tests\TestCase;

class UngatedResourceReadTest extends TestCase
{
    private array $projected = [];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('g', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', FrameReadActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
        $this->assertInstanceOf(RealmEntitlementResourceGate::class, app(ResourceAccessGate::class));

        Schema::create('frame_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        $actor = FrameReadActor::create(['name' => 'Ada']);
        $foreign = FrameReadActor::create(['name' => 'Bo']);
        Gadget::create(['user_id' => $actor->getKey()]);
        Gadget::create(['user_id' => $foreign->getKey()]);
        $this->actingAs($actor);
    }

    private function declare(string $key = 'gadgets', ?Closure $scope = null): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: Gadget::class,
            data: GadgetData::class,
            scope: $scope,
            project: function (Gadget $gadget): GadgetData {
                $this->projected[] = $gadget->getKey();

                return new GadgetData((string) $gadget->getKey());
            },
            readOnly: true,
            label: 'Gadgets',
        ));
    }

    public function test_canonical_list_refuses_the_same_ungated_population_as_particle_index(): void
    {
        $this->declare();
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->assertSame([], $this->projected);
    }

    public function test_known_record_id_cannot_replace_a_declared_read_boundary(): void
    {
        $this->declare();

        $this->getJson('/frame/resources/gadgets/records/2')->assertForbidden();
        $this->assertSame([], $this->projected);
    }

    public function test_summary_refuses_the_ungated_population_before_aggregation(): void
    {
        $this->declare();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/frame/resources/gadgets/summary')->assertForbidden();
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertSame([], array_values(array_filter($queries, fn (string $query): bool => str_contains(strtolower($query), 'count('))));
    }

    public function test_declared_owner_scope_still_serves_list_detail_and_summary(): void
    {
        $this->declare(scope: fn ($query) => $query->where('user_id', request()->user()->getAuthIdentifier()));

        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', '1');
        $this->getJson('/frame/resources/gadgets/records/1')->assertOk()->assertJsonPath('data.id', '1');
        $this->getJson('/frame/resources/gadgets/records/2')->assertNotFound();
        $this->getJson('/frame/resources/gadgets/summary')->assertOk()->assertJsonPath('figures.0.value', 1);
        $this->assertSame([1, 1], $this->projected);
    }

    public function test_user_query_controls_do_not_authorize_a_bare_resource(): void
    {
        $this->declare();

        $this->getJson('/frame/resources/gadgets?filter[user_id]=1')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/summary?filter[user_id]=1')->assertForbidden();
    }

    public function test_a_predicated_query_preserves_the_owned_list_and_aggregate(): void
    {
        $this->declare();
        DataFilter::registry()->registerDefinition(new FilterDefinition('gadgets', GadgetData::class, OwnedGadgetQuery::class, Gadget::class));

        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '1');
        $this->getJson('/frame/resources/gadgets/summary')->assertOk()->assertJsonPath('figures.0.value', 1);
    }

    public function test_a_gate_before_superuser_can_read_the_otherwise_ungated_population(): void
    {
        $this->declare();
        Gate::before(fn () => true);

        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/frame/resources/gadgets/records/2')->assertOk()->assertJsonPath('data.id', '2');
        $this->getJson('/frame/resources/gadgets/summary')->assertOk()->assertJsonPath('figures.0.value', 2);
    }

    public function test_a_relative_list_with_a_valid_variant_keeps_its_parent_intersection(): void
    {
        $this->declare();
        DataFilter::registry()->registerDefinition(new FilterDefinition('gadgets', GadgetData::class, BareGadgetQuery::class, Gadget::class));
        DataFilter::registry()->registerDefinition(new FilterDefinition('gadgets-variant', GadgetData::class, BareGadgetQuery::class, Gadget::class, 'gadgets'));
        Particle::relative('parents', Gadget::class, fn (Gadget $parent) => Gadget::query()->where('user_id', $parent->user_id), function (): void {
            Particle::mount('gadgets')->only(['index'])->register();
        });

        $this->getJson('/parents/1/gadgets?filterVariant=gadgets-variant')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '1');
    }

    public function test_a_pure_frame_list_definition_does_not_acquire_beam_authority(): void
    {
        $definition = new ResourceDefinition(
            key: 'pure-frame-gadgets', model: Gadget::class, data: GadgetData::class,
            creatable: false, query: null, editData: null, policy: null, form: 'bare',
            nav: new NavMetadata('Gadgets'), deletable: false, editable: false,
        );
        $this->assertFalse(app(ParticleResourceRegistry::class)->has($definition->key));
        $this->assertFalse(Gate::allows('viewAny', Gadget::class));

        $this->assertSame(2, app(ScopedIndexQuery::class)->forDefinition($definition)->count());
    }

    public function test_policy_presence_is_not_a_second_policy_permission_check(): void
    {
        $this->declare();
        Gate::policy(Gadget::class, RefusingFrameGadgetPolicy::class);
        $this->assertFalse(Gate::allows('viewAny', Gadget::class));

        $this->assertTrue(ResourceReadGuard::forApp()->inspectRead(app(ParticleResourceRegistry::class)->get('gadgets'), request())->allowed());
    }

    public function test_an_indeterminate_probe_does_not_hide_the_real_query_failure(): void
    {
        $this->declare(scope: fn () => throw new RuntimeException('Unavailable domain context'));
        $resource = app(ParticleResourceRegistry::class)->get('gadgets');
        $this->assertNull(ResourceReadGuard::forApp()->scoped($resource, request()));
        $this->assertTrue(ResourceReadGuard::forApp()->inspectRead($resource, request())->allowed());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unavailable domain context');
        app(ScopedIndexQuery::class)->forDefinition($resource->toResourceDefinition());
    }

    public function test_a_model_less_backing_does_not_acquire_a_model_policy_requirement(): void
    {
        $resource = new ParticleResource(key: 'external', backing: new FrameReadExternalBacking, data: GadgetData::class, readOnly: true);

        $this->assertNull(ResourceReadGuard::forApp()->policyBound($resource));
        $this->assertTrue(ResourceReadGuard::forApp()->inspectRead($resource, request())->allowed());
    }
}

class FrameReadActor extends User
{
    protected $table = 'frame_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}

class RefusingFrameGadgetPolicy
{
    public function viewAny(mixed $user): bool
    {
        return false;
    }
}

class FrameReadExternalBacking implements ResourceBacking {}
