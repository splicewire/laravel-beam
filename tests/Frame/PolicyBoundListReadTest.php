<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The list read asks the bound policy's viewAny (launch security row 51a71469). Until this, a bound policy was a PASS for
 * the list: `ResourceReadGuard::inspect()` returned allow as soon as any policy was bound, the list asked nothing else,
 * and its rows were narrowed only by a declared scope. So every policy-bound, row-unscoped resource listed in full to any
 * signed-in actor, a user with no team and no token included, while viewAny gated only the nav seat.
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

    private function declare(?\Closure $scope = null): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: $scope,
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

    public function test_a_bound_policy_refuses_the_list_to_an_actor_its_view_any_refuses(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare();

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/gadgets')->assertForbidden();

        $this->as('holder');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/gadgets')->assertOk();
    }

    public function test_a_gate_before_superuser_still_lists_it(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare();
        Gate::before(fn () => true);

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_a_declared_narrowing_scope_still_serves_its_own_rows(): void
    {
        Gate::policy(Gadget::class, HolderOnlyGadgetPolicy::class);
        $this->declare(fn ($query) => $query->where('user_id', auth()->id()));

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', '2');
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
        $this->getJson('/frame/resources/soft-gadgets')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_a_policy_without_view_any_keeps_todays_pass_as_listable_reads_it(): void
    {
        Gate::policy(Gadget::class, NoViewAnyGadgetPolicy::class);
        $this->declare();

        $this->as('no-team');
        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('total', 2);
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

class SoftGadget extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'gadgets';

    protected $guarded = [];

    public $timestamps = false;
}
