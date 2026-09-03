<?php

namespace Splicewire\Beam\Tests\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\PermissionCascade\Support\PermissionNamer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence 147 — `schemas` was one of the eleven resources whose backing model carried NO
 * policy after 135 bound `Hook`'s. `BeamSchema`'s own docblock promised a "policy seam (schemas may be
 * permissioned)" and nothing had ever used it; the absence read four ways at once — filters fell
 * through, `show` denied everyone but a host's Root bypass, the write pipeline denied, the Frame nav
 * hid the seat. `#[UseCascadePolicy]` on the model, bound beside `Hook` in `packageBooted()`, is one
 * declaration consumed by all four, under the `beam_schema` alias this package already owned
 * (ADR-0118). The host's `schemas.manage` verb (freeze/migrate) is a separate `Gate::define` and is
 * untouched — the policy answers only the seven standard verbs and no longer shadows a defined ability.
 *
 * Gate CLOSED: no `Gate::before(fn () => true)`, the control probe first, spatie's plane booted so a
 * holder is a principal the cascade can admit.
 */
class BeamSchemaPolicyGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', SchemaPolicyGateUser::class);
        // The cascade forces spatie into teams mode; this harness has no team, and a null `team_id`
        // fails spatie's composite key on every grant.
        $app['config']->set('permission-cascade.manage_spatie_teams', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name')->nullable()->index();
            $table->integer('version')->nullable();
            $table->string('fingerprint');
            $table->string('artifact');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->nullable();
        });

        $this->createSpatiePermissionSchema();
    }

    public function test_control_the_gate_is_closed_for_a_stranger(): void
    {
        $this->assertFalse(Gate::forUser($this->stranger())->allows('probe-nonexistent-ability'));
    }

    public function test_control_the_cascade_provider_is_booted_not_auto_resolved(): void
    {
        $this->assertSame(app(PermissionNamer::class), app(PermissionNamer::class));
    }

    public function test_beam_schema_binds_a_cascade_policy_that_answers_view_any(): void
    {
        $policy = Gate::getPolicyFor(BeamSchema::class);

        $this->assertInstanceOf(ConfiguredModelPolicy::class, $policy);
        $this->assertTrue(method_exists($policy, 'viewAny'));
    }

    public function test_the_token_prefix_is_the_alias(): void
    {
        $this->assertSame('beam-schema.view', app(PermissionNamer::class)->assemble(BeamSchema::class, 'view'));
    }

    public function test_a_stranger_is_denied_every_ability(): void
    {
        $gate = Gate::forUser($this->stranger());
        $row = $this->schema();

        $this->assertFalse($gate->allows('viewAny', BeamSchema::class));
        $this->assertFalse($gate->allows('view', $row));
        $this->assertFalse($gate->allows('create', BeamSchema::class));
        $this->assertFalse($gate->allows('delete', $row));
    }

    public function test_a_holder_of_the_class_family_is_admitted_gate_closed(): void
    {
        $gate = Gate::forUser($this->holder('beam-schema.view', 'beam-schema.create'));
        $row = $this->schema();

        $this->assertFalse($gate->allows('probe-nonexistent-ability'));
        $this->assertTrue($gate->allows('viewAny', BeamSchema::class));
        $this->assertTrue($gate->allows('view', $row));
        $this->assertTrue($gate->allows('create', BeamSchema::class));
        $this->assertFalse($gate->allows('delete', $row));
    }

    public function test_the_policy_does_not_shadow_the_hosts_own_defined_verb(): void
    {
        // `schemas.manage` is a host `Gate::define`, not a policy method. A policy on the model must
        // leave it alone — the `__call()` that used to eat every unknown ability is gone (cascade 0b2a531).
        Gate::define('schemas.manage', fn ($user) => true);

        $this->assertTrue(Gate::forUser($this->stranger())->allows('schemas.manage'));
    }

    private function schema(): BeamSchema
    {
        return BeamSchema::query()->create([
            'schema_id' => 'https://probe.test/schemas/probe/1.json',
            'schema_name' => 'probe',
            'version' => 1,
            'fingerprint' => 'abc',
            'artifact' => '{}',
        ]);
    }

    private function stranger(): SchemaPolicyGateUser
    {
        return SchemaPolicyGateUser::create(['email' => 'm'.mt_rand().'@beam.test']);
    }

    private function holder(string ...$abilities): SchemaPolicyGateUser
    {
        $user = $this->stranger();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createSpatiePermissionSchema(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });
    }
}

class SchemaPolicyGateUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
