<?php

namespace Splicewire\Beam\Tests\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\GrantedExplicitly;
use Rushing\PermissionCascade\Support\PermissionNamer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Authorization\BeamSchemaPolicy;
use Splicewire\Beam\Authorization\GitRepoPolicy;
use Splicewire\Beam\Authorization\GitRepoReadPolicy;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Models\GitRepo;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `git-repo` was the one beam-owned resource every starter lists in its tenant realm with NO read
 * boundary: no policy on `GitRepo`, no row predicate, no tenancy, and an ungated realm. 473fbfd made
 * {@see ResourceReadGuard} refuse every read of such a resource, and 88f872fd3 made the nav and the realm
 * dashboard ask that guard — so the seat vanished for every principal, including the team members the
 * cascade grants `view` to on every sibling resource (`Hook`, `BeamSchema`). The boundary is declared
 * the way theirs is: `#[UseCascadePolicy]`, bound in `packageBooted()`, under the `git_repo` alias.
 *
 * Gate CLOSED, as {@see BeamSchemaPolicyGateTest}: no `Gate::before`, a control probe, spatie booted.
 */
class GitRepoPolicyGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', GitRepoPolicyGateUser::class);
        $app['config']->set('permission-cascade.manage_spatie_teams', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(Beam::table('git_repos'));
        Schema::create(Beam::table('git_repos'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('root_path')->unique();
            $table->string('branch')->nullable();
            $table->string('head_sha')->nullable();
            $table->json('dirty_paths');
            $table->json('untracked_paths');
            $table->json('tracked_paths');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->nullable();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                if ($name === 'roles') {
                    $t->unsignedBigInteger('team_id')->nullable();
                }
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
            });
        }
        foreach (['model_has_permissions' => 'permission_id', 'model_has_roles' => 'role_id'] as $name => $key) {
            Schema::create($name, function (Blueprint $t) use ($key): void {
                $t->unsignedBigInteger($key);
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->unsignedBigInteger('team_id')->nullable();
            });
        }
        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });
    }

    public function test_control_the_gate_is_closed_for_a_stranger(): void
    {
        $this->assertFalse(Gate::forUser($this->stranger())->allows('probe-nonexistent-ability'));
    }

    public function test_git_repo_binds_a_cascade_policy_under_its_alias(): void
    {
        // UX-08c: a RESERVED cascade policy. Its tokens go to no team role by default (a member would read a host's
        // repo roots and dirty paths); a host grants them by name. The token names are unchanged.
        $this->assertInstanceOf(GitRepoPolicy::class, Gate::getPolicyFor(GitRepo::class));
        $this->assertInstanceOf(GrantedExplicitly::class, Gate::getPolicyFor(GitRepo::class));
        $this->assertSame('git-repo.view', app(PermissionNamer::class)->assemble(GitRepo::class, 'view'));
    }

    public function test_beam_schema_binds_a_reserved_cascade_policy_too(): void
    {
        $this->assertInstanceOf(BeamSchemaPolicy::class, Gate::getPolicyFor(BeamSchema::class));
        $this->assertInstanceOf(GrantedExplicitly::class, Gate::getPolicyFor(BeamSchema::class));
        $this->assertSame('beam-schema.view', app(PermissionNamer::class)->assemble(BeamSchema::class, 'view'));
    }

    public function test_a_stranger_is_denied_and_a_view_holder_is_admitted(): void
    {
        $row = $this->repo();

        $stranger = Gate::forUser($this->stranger());
        $this->assertFalse($stranger->allows('viewAny', GitRepo::class));
        $this->assertFalse($stranger->allows('view', $row));

        $holder = Gate::forUser($this->holder('git-repo.view'));
        $this->assertTrue($holder->allows('viewAny', GitRepo::class));
        $this->assertTrue($holder->allows('view', $row));
        $this->assertFalse($holder->allows('delete', $row));
    }

    /** The resource the starters list is now policy-bound, so the read guard answers through the policy. */
    public function test_the_git_repo_resource_reads_through_its_policy(): void
    {
        $resource = app(ParticleResourceRegistry::class)->find('git-repo');

        $this->assertNotNull($resource, 'beam declares the git-repo resource');
        $this->assertSame(GitRepoReadPolicy::class, $resource->readPolicy);
        $this->assertTrue(ResourceReadGuard::forApp()->policyBound($resource));
        $this->assertTrue(ResourceReadGuard::forApp()->inspectReadFor($resource, Request::create('/'), $this->holder('git-repo.view'))->allowed());
    }

    public function test_the_git_repo_projection_admits_an_operator_without_widening_the_model_policy(): void
    {
        $resource = app(ParticleResourceRegistry::class)->find('git-repo');
        $operator = $this->stranger();
        $member = $this->stranger();
        Gate::define('entitlement:os.operate', fn (GitRepoPolicyGateUser $user): bool => $user->is($operator));

        $this->assertFalse(Gate::forUser($operator)->allows('viewAny', GitRepo::class));
        $this->assertTrue(ResourceReadGuard::forApp()->inspectReadFor($resource, Request::create('/'), $operator)->allowed());
        $this->assertTrue(ResourceReadGuard::forApp()->inspectReadFor($resource, Request::create('/'), $member)->denied());
    }

    private function repo(): GitRepo
    {
        return GitRepo::query()->create([
            'root_path' => '/probe/'.mt_rand(),
            'dirty_paths' => [],
            'untracked_paths' => [],
            'tracked_paths' => [],
        ]);
    }

    private function stranger(): GitRepoPolicyGateUser
    {
        return GitRepoPolicyGateUser::create(['email' => 'm'.mt_rand().'@beam.test']);
    }

    private function holder(string ...$abilities): GitRepoPolicyGateUser
    {
        $user = $this->stranger();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

class GitRepoPolicyGateUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
