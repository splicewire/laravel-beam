<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The list read requires BOTH the bound policy's viewAny and a declared scope (integrator ruling 2026-10-10 02:43Z).
 * Neither half substitutes for the other: policy admission without a row/tenant/realm boundary would expose the whole
 * population, while a scope without policy admission let a role-less member read rows a policy explicitly withheld.
 *
 * A policy WITHOUT a viewAny method keeps today's pass, exactly as `ResourceVisibility::listable()` reads it, so the read and
 * the nav cannot disagree; that residual open list is enumerated and ratcheted, not hidden.
 */
class PolicyBoundListReadTest extends TestCase
{
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
        $app['config']->set('auth.providers.users.model', ListReadActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('list_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->softDeletes();
        });
        ListReadActor::create(['name' => 'holder']);
        ListReadActor::create(['name' => 'no-team']);
        Gadget::create(['user_id' => 1]);
        Gadget::create(['user_id' => 2]);
    }

    private function declare(?\Closure $scope = null, ?string $readPolicy = null): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: $scope,
            readPolicy: $readPolicy,
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Gadgets',
        ));
        Particle::mount('gadgets')->only(['index'])->register();
    }

    private function as(string $name): void
    {
        $this->actingAs(ListReadActor::query()->where('name', $name)->firstOrFail());
    }

    public function test_view_any_without_a_scope_refuses_the_list(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare();

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertForbidden();
        $this->getJson('/gadgets')->assertForbidden();

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertForbidden();
        $this->getJson('/gadgets')->assertForbidden();
    }

    public function test_a_gate_before_superuser_still_needs_a_scope(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare();
        Gate::before(fn () => true);

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertForbidden();
    }

    public function test_a_declared_scope_without_view_any_refuses_the_list(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(fn ($query) => $query->where('user_id', auth()->id()));

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertForbidden();
    }

    public function test_view_any_and_a_declared_scope_serve_the_scoped_rows(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(fn ($query) => $query->where('user_id', auth()->id()));

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', '1');
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertOk();
        $this->getJson('/gadgets')->assertOk();
    }

    public function test_a_resource_read_policy_replaces_only_the_model_view_any_arm(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(
            fn ($query) => $query->where('user_id', auth()->id()),
            AuthenticatedResourceReadPolicy::class,
        );

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', '2');
        $this->getJson('/gadgets')->assertOk();
    }

    public function test_a_resource_read_policy_deny_and_the_scope_half_each_fail_closed(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(
            fn ($query) => $query->where('user_id', auth()->id()),
            DenyingResourceReadPolicy::class,
        );

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'unscoped-gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            readPolicy: AuthenticatedResourceReadPolicy::class,
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Unscoped gadgets',
        ));
        $this->getJson('/frame/resources/unscoped-gadgets')->assertForbidden();
    }

    public function test_filter_metadata_uses_the_same_realm_entitled_decision_as_the_list(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        config(['beam.core.realm_gates' => ['staffroom' => ['entitlement' => 'staff.read']]]);
        app(ParticleResourceRegistry::class)->loadRealmMap(['staffroom' => ['gadgets']]);
        Gate::define('entitlement:staff.read', fn ($actor) => $actor?->name === 'holder');
        $this->declare();

        $this->assertFalse(app(ResourceReadGuard::class)->scoped(
            app(ParticleResourceRegistry::class)->get('gadgets'),
            request(),
        ));

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertOk();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertOk();
        $this->getJson('/frame/resources/gadgets/filters/variants')->assertOk();

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/filters/variants')->assertForbidden();
    }

    /**
     * A GLOBAL scope is not an authorization scope (review-r1, build.qa): `deleted_at is null` put a where on the base, so
     * the declared-scope allowance read the list as narrowed and never asked viewAny. Measured live: a no-team user listed
     * all 34 entries at the tower starter through beam-ux's SoftDeletes BeamUxEntry. Only the resource's own declared scope
     * and its data-filter authorization narrow now.
     */
    public function test_a_soft_delete_global_scope_is_not_a_narrowing_scope(): void
    {
        Gate::policy(SoftGadget::class, HolderOnlyGadgetPolicy::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'soft-gadgets',
            backing: SoftGadget::class,
            data: GadgetData::class,
            project: fn (SoftGadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Soft gadgets',
        ));

        $this->as('no-team');
        $this->getJson('/frame/resources/soft-gadgets')->assertForbidden();

        $this->as('holder');
        $this->getJson('/frame/resources/soft-gadgets')->assertForbidden();
    }

    /**
     * A throw while building the authorization bases is not a scope (build.qa, follow-on to row 51a71469). It used to read
     * as `scoped() === null`, and the list ALLOWED. Now it is reported, and the read asks viewAny.
     */
    public function test_an_authorization_base_that_throws_is_reported_and_asks_view_any(): void
    {
        Exceptions::fake();
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(fn ($query) => throw new \RuntimeException('the boundary cannot be built here'));

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'the boundary cannot be built here');
    }

    public function test_a_policy_without_view_any_keeps_todays_pass_as_listable_reads_it(): void
    {
        Gate::policy(Gadget::class, NoViewAnyGadgetPolicy::class);
        $this->declare();

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/frame/resources/gadgets/filters/schema')->assertOk();
    }
}

class ListReadActor extends User
{
    protected $table = 'list_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}

class HolderOnlyGadgetPolicy
{
    public function viewAny(mixed $user): bool
    {
        return $user?->name === 'holder';
    }

    public function view(mixed $user, mixed $gadget): bool
    {
        return $user?->name === 'holder';
    }
}

class NoViewAnyGadgetPolicy
{
    public function view(mixed $user, mixed $gadget): bool
    {
        return true;
    }
}

class AuthenticatedResourceReadPolicy implements ResourceReadPolicy
{
    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        return $actor === null ? Response::deny('Authentication required.') : Response::allow();
    }
}

class DenyingResourceReadPolicy implements ResourceReadPolicy
{
    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        return Response::deny('Resource-specific denial.');
    }
}

class SoftGadget extends Model
{
    use SoftDeletes;

    protected $table = 'gadgets';

    protected $guarded = [];

    public $timestamps = false;
}
