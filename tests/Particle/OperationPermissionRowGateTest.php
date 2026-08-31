<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-write-surface ticket 02 — **the load-bearing assumption, traced end to end.**
 *
 * The ticket's whole recommendation (close five `ability: null` write ops by declaring a dotted
 * permission name) rests on one claim that had never been exercised:
 *
 * > a `spatie/laravel-permission` permission ROW named `{resource}.{name}` is sufficient to pass
 * > `ability:` through {@see \Splicewire\Beam\Http\Particle\ParticleOperationController}, with **no
 * > policy existing** on the subject model.
 *
 * It was *inferred* from {@see \Splicewire\Beam\Authorization\AbilityResolver::allows()} —
 * `Gate::forUser($actor)->allows($ability, $subject)` — plus spatie's
 * `PermissionRegistrar::registerPermissions()`, whose `Gate::before` ignores the subject argument
 * entirely. Inference, on both halves. This class turns it into a measurement.
 *
 * ## Gate posture: CLOSED, stated because a security test without it is worth nothing
 *
 * **No `Gate::before(fn () => true)` anywhere in this class.** No policy is registered on the subject
 * model at all — `Gate` is deny-by-default over it, which
 * {@see test_control_the_gate_is_closed_and_the_ability_is_undefined_before_any_row_exists()} asserts
 * as a control *before* any finding is drawn. The only `Gate::before` in play is the one spatie
 * installs, which is the mechanism under test.
 *
 * **Both directions are asserted**, on the same op, in the same class: a role-less member is refused
 * and the grant-holder is admitted. A one-directional version of this test would pass whether the
 * gate ran or not — the shape that let `api-surface-coherence` 65 record a hole that was not there.
 *
 * ## Two deliberate simplifications, and why neither weakens the finding
 *
 *   - **The ambient permissions team is left unset (null)**, so the grant is a central one. Teams
 *     mode itself is ON — `rushing/laravel-permission-cascade` turns it on and beam boots that
 *     provider, which is why the schema below carries `team_id`. Which principals hold a row under a
 *     given team is a scoping question exercised where it is owned (that package's `TeamScopingTest`);
 *     this class asks only whether *holding* it passes `ability:`.
 *   - **The grant is direct (`givePermissionTo`), not via a role.** Same reason: the registrar's
 *     `before` asks `checkPermissionTo($ability)`, which does not care which edge supplied the row.
 *
 * ## What this pins that nothing else did
 *
 * That a **dotted, derived-shaped** name (`{resource}.{name}`, exactly what
 * {@see ParticleOperation::permissionName()} returns) is a live Gate ability by virtue of the row
 * alone. If this class ever goes red, every `ability:` in the estate that names a permission row
 * rather than a policy verb is silently ungated, and ticket 02's five declarations are decorations.
 */
class OperationPermissionRowGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Appended, not substituted: beam's own harness list is load-bearing (see TestCase).
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', GateProbeUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gate_probe_things', function (Blueprint $table): void {
            $table->id();
            $table->boolean('touched')->default(false);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
        });

        $this->createSpatieSchema();

        // GATE CLOSED. Note what is absent: no `Gate::policy(GateProbeThing::class, …)`, and no
        // `Gate::before(fn () => true)`. Nothing in this app can allow anything against the subject
        // except a permission row.
    }

    // ── Controls, asserted before any finding ───────────────────────────────────────────────────

    public function test_control_the_gate_is_closed_and_the_ability_is_undefined_before_any_row_exists(): void
    {
        $member = $this->user();
        $thing = GateProbeThing::create([]);

        // Deny-by-default over an unpoliced model, on a bare policy verb…
        $this->assertFalse(Gate::forUser($member)->allows('update', $thing));
        // …and on the derived dotted name this ticket proposes to declare. Undefined ⇒ denied.
        $this->assertFalse(Gate::forUser($member)->allows('gate-probe-things.touch', $thing));
    }

    public function test_control_a_permission_row_nobody_holds_grants_nobody(): void
    {
        Permission::findOrCreate('gate-probe-things.touch', 'web');
        $member = $this->user();

        $this->assertFalse(Gate::forUser($member)->allows('gate-probe-things.touch', GateProbeThing::create([])));
    }

    // ── The trace itself, both directions, through the real controller ──────────────────────────

    public function test_a_role_less_member_is_refused_by_a_declared_permission_name(): void
    {
        Permission::findOrCreate('gate-probe-things.touch', 'web');
        $this->mount();

        $thing = GateProbeThing::create([]);
        $this->actingAs($this->user());

        $this->postJson("/gate-probe-things/{$thing->id}/op/touch")->assertForbidden();

        $this->assertFalse($thing->fresh()->touched, 'refused ⇒ no state change');
    }

    public function test_a_permission_row_alone_passes_the_operation_ability_with_no_policy_present(): void
    {
        $permission = Permission::findOrCreate('gate-probe-things.touch', 'web');
        $this->mount();

        $holder = $this->user();
        $holder->givePermissionTo($permission);
        $this->forgetPermissionCache();

        // The direct control first: the row, and nothing else, moved the Gate.
        $this->assertTrue(Gate::forUser($holder)->allows('gate-probe-things.touch', GateProbeThing::create([])));

        $thing = GateProbeThing::create([]);
        $this->actingAs($holder);

        $this->postJson("/gate-probe-things/{$thing->id}/op/touch")->assertOk();

        $this->assertTrue($thing->fresh()->touched, 'admitted ⇒ the effect happened');
    }

    /**
     * The row is checked as the ABILITY, not as a policy verb on the subject — spatie's `before`
     * discards the subject argument. Proven by granting the row and asserting the op passes while
     * the subject model still has no policy of any kind (asserted directly above), i.e. there is no
     * `GateProbeThingPolicy::touch()` anywhere for the cascade to have found.
     */
    public function test_the_grant_is_subject_blind_so_it_admits_any_row_of_the_resource(): void
    {
        $permission = Permission::findOrCreate('gate-probe-things.touch', 'web');
        $this->mount();

        $holder = $this->user();
        $holder->givePermissionTo($permission);
        $this->forgetPermissionCache();
        $this->actingAs($holder);

        $a = GateProbeThing::create([]);
        $b = GateProbeThing::create([]);

        $this->postJson("/gate-probe-things/{$a->id}/op/touch")->assertOk();
        $this->postJson("/gate-probe-things/{$b->id}/op/touch")->assertOk();

        $this->assertTrue($a->fresh()->touched);
        $this->assertTrue($b->fresh()->touched);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function user(): GateProbeUser
    {
        return GateProbeUser::create(['email' => 'u'.mt_rand().'@beam.test']);
    }

    private function forgetPermissionCache(): void
    {
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function mount(): void
    {
        $operation = new ParticleOperation(
            resource: 'gate-probe-things',
            name: 'touch',
            kind: OperationKind::Write,
            model: GateProbeThing::class,
            // The declaration under test: the derived name, checked against the resolved instance
            // (`abilityModel` left null — the entitlement plane is deliberately NOT selected).
            ability: 'gate-probe-things.touch',
            handle: function ($model) {
                $model->touched = true;
                $model->save();

                return ['ok' => true];
            },
        );

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gate-probe-things',
            backing: GateProbeThing::class,
        ));

        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('gate-probe-things', 'gate-probe-things', $operation->name);
    }

    private function createSpatieSchema(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }
}

class GateProbeThing extends Model
{
    protected $table = 'gate_probe_things';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['touched' => 'bool'];
}

class GateProbeUser extends User
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
