<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\ResourceFilters;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough UX-08c: a MODEL-backed resource that declares an ability string as its `policy` is read-gated by it, on
 * top of its model's policy. The case it exists for: two diagnostics backed by the model the Entries resource uses, whose
 * policy every member passes. Decided in ONE place, `ResourceReadGuard`, which the list, the detail, the filters and the
 * nav listing all ask. A class-string `policy` (the model-backed idiom, `UserPolicy::class`) is never treated as an
 * ability: asked as one it is undefined, and would refuse everyone.
 */
class DeclaredReadAbilityTest extends TestCase
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
        $app['config']->set('auth.providers.users.model', DeclaredAbilityActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('declared_ability_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        DeclaredAbilityActor::create(['name' => 'admin']);
        DeclaredAbilityActor::create(['name' => 'member']);
        Gadget::create(['user_id' => 1]);

        // The model's own policy admits every principal, as the entries model's does for its members.
        Gate::policy(Gadget::class, EveryoneReadsGadgets::class);
        // The diagnostics ability: a team's admin holds it, a member does not.
        Gate::define('gadget.diagnostics.view', fn (DeclaredAbilityActor $user): bool => $user->name === 'admin');
    }

    private function declare(?string $policy): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            // Integrator ruling 2026-10-10 02:43Z: this feature fixture's all-row population
            // boundary composes with viewAny; the declared ability remains the narrower gate under test.
            scope: fn (Builder $query): Builder => $query->whereNotNull($query->getModel()->getQualifiedKeyName()),
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Gadgets',
            policy: $policy,
        ));
        // The canonical REST mount beside the frame socket: its own list and detail, ParticleController::index/show.
        Particle::mount('gadgets')->only(['index', 'show'])->register();
    }

    private function as(string $name): DeclaredAbilityActor
    {
        $actor = DeclaredAbilityActor::query()->where('name', $name)->firstOrFail();
        $this->actingAs($actor);

        return $actor;
    }

    private function listable(DeclaredAbilityActor $actor): bool
    {
        $definition = app(ParticleResourceRegistry::class)->find('gadgets')->toResourceDefinition();

        return app(ResourceVisibility::class)->listable($definition, $actor);
    }

    private function filtersAdmit(): bool
    {
        try {
            app(ResourceFilters::class)->authorizeModel(Gadget::class, app(ParticleResourceRegistry::class)->find('gadgets'));

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function test_a_member_without_the_declared_ability_is_refused_on_every_read_path(): void
    {
        $this->declare('gadget.diagnostics.view');
        $member = $this->as('member');

        $this->getJson('/frame/resources/gadgets')->assertForbidden();
        $this->getJson('/frame/resources/gadgets/records/1')->assertForbidden();
        $this->getJson('/gadgets')->assertForbidden();
        $this->getJson('/gadgets/1')->assertForbidden();
        $this->assertFalse($this->filtersAdmit(), 'the filters endpoint refuses too');
        $this->assertFalse($this->listable($member), 'and the nav never offers the seat');
    }

    public function test_a_holder_of_the_declared_ability_reads_it(): void
    {
        $this->declare('gadget.diagnostics.view');
        $admin = $this->as('admin');

        $this->getJson('/frame/resources/gadgets')->assertOk()->assertJsonPath('data.0.id', '1');
        $this->getJson('/frame/resources/gadgets/records/1')->assertOk()->assertJsonPath('data.id', '1');
        $this->getJson('/gadgets')->assertOk();
        $this->getJson('/gadgets/1')->assertOk();
        $this->assertTrue($this->filtersAdmit());
        $this->assertTrue($this->listable($admin));
    }

    public function test_a_gate_before_superuser_reads_it(): void
    {
        $this->declare('gadget.diagnostics.view');
        $member = $this->as('member');
        Gate::before(fn () => true);

        $this->getJson('/frame/resources/gadgets')->assertOk();
        $this->getJson('/frame/resources/gadgets/records/1')->assertOk();
        $this->assertTrue($this->listable($member));
    }

    public function test_a_class_string_policy_is_not_an_ability_and_changes_nothing(): void
    {
        $this->declare(EveryoneReadsGadgets::class);
        $member = $this->as('member');

        $this->getJson('/frame/resources/gadgets')->assertOk();
        $this->getJson('/frame/resources/gadgets/records/1')->assertOk();
        $this->assertTrue($this->listable($member));
    }
}

class DeclaredAbilityActor extends User
{
    protected $table = 'declared_ability_actors';

    protected $guarded = [];

    public $timestamps = false;
}

class EveryoneReadsGadgets
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, mixed $gadget): bool
    {
        return true;
    }
}
