<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Backing\CentralParticleBacking;
use Splicewire\Beam\Particle\Backing\SharedParticleAccess;
use Splicewire\Beam\Particle\Backing\WorkspaceSharedParticleBacking;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;

class SharedParticleTest extends TestCase
{
    private SharedTestTenancy $tenancy;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('s', 32));
        $app['config']->set('frame.middleware', ['web', 'auth']);
        foreach (['shared_central', 'shared_a', 'shared_b'] as $connection) {
            $app['config']->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenancy = new SharedTestTenancy;
        $this->app->instance(TenancyContext::class, $this->tenancy);
        foreach (['shared_central', 'shared_a', 'shared_b'] as $connection) {
            Schema::connection($connection)->create(Beam::table('schemas'), function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_id')->unique();
                $table->string('schema_name');
                $table->integer('version');
                $table->string('fingerprint');
                $table->json('artifact');
                $table->timestamps();
            });
            Schema::connection($connection)->create(Beam::table('particles'), function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_ref')->nullable();
                $table->string('schema_id')->nullable();
                $table->string('migration_status')->nullable();
                $table->string('head_version')->nullable();
                $table->string('source_tier')->default('local');
                $table->json('payload');
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
        // Schema definitions are a host registry. Pin this fixture registry centrally;
        // particle storage itself must choose its connection through the backing.
        $schemas = new DatabaseSchemaRegistry(connection: 'shared_central');
        $this->app->instance(SchemaRegistry::class, $schemas);
        $this->app->instance(SchemaTargetResolver::class, new RegistrySchemaTargetResolver($schemas));
        app(AttributedParticleDiscovery::class)->discover(classes: [SharedNoteRead::class, SharedOtherRead::class, CentralNoteRead::class]);
        foreach (['shared-notes', 'shared-other', 'central-notes'] as $key) {
            Particle::mount($key, $key);
        }
        Gate::define('test.shared.manage', fn (User $actor) => $actor->id !== 99);
        $this->workspace('a', 'shared_a');
        $this->actor(10);
    }

    private function actor(int $id): void
    {
        $actor = new User;
        $actor->id = $id;
        $this->actingAs($actor);
    }

    private function workspace(string $id, string $connection): void
    {
        $this->tenancy->id = $id;
        config(['database.default' => $connection]);
    }

    public function test_two_actors_share_rest_and_frame_but_other_workspace_and_schema_do_not(): void
    {
        $id = $this->postJson('/shared-notes', ['name' => 'team'])->assertCreated()->json('data.id');
        $this->putJson('/frame/resources/shared-notes/records/'.$id, ['name' => 'frame creator'])->assertOk();
        $this->getJson('/frame/resources/shared-notes')->assertOk()->assertJsonPath('total', 1);
        $this->actor(11);
        $this->getJson('/shared-notes/'.$id)->assertOk()->assertJsonPath('data.name', 'frame creator');
        $this->putJson('/shared-notes/'.$id, ['name' => 'rest teammate'])->assertOk();
        $this->getJson('/frame/resources/shared-notes')->assertOk()->assertJsonPath('total', 1);
        $this->putJson('/frame/resources/shared-notes/records/'.$id, ['name' => 'frame teammate'])->assertOk();
        $this->getJson('/shared-notes/'.$id)->assertOk()->assertJsonPath('data.name', 'frame teammate');
        $this->getJson('/shared-other/'.$id)->assertNotFound();

        // Deliberately keep the same physical DB: the trusted workspace envelope
        // must isolate records independently of the host's database topology.
        $this->workspace('b', 'shared_a');
        $this->getJson('/shared-notes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/frame/resources/shared-notes')->assertOk()->assertJsonPath('total', 0);
        foreach (['/shared-notes/'.$id, '/frame/resources/shared-notes/records/'.$id] as $path) {
            $this->getJson($path)->assertNotFound();
            $this->putJson($path, ['name' => 'intruder'])->assertNotFound();
            $this->deleteJson($path)->assertNotFound();
        }
        $this->workspace('a', 'shared_a');
        $this->assertSame('frame teammate', BeamParticle::findOrFail($id)->payload['name']);
    }

    public function test_central_storage_crosses_initialized_tenants_and_checks_ability_and_guests(): void
    {
        $id = $this->postJson('/central-notes', ['name' => 'global'])->assertCreated()->json('data.id');
        $this->assertSame(0, BeamParticle::on('shared_a')->count());
        $this->assertSame(1, BeamParticle::on('shared_central')->count());
        $this->workspace('b', 'shared_b');
        $this->actor(11);
        $this->getJson('/central-notes/'.$id)->assertOk()->assertJsonPath('data.name', 'global');
        $this->putJson('/frame/resources/central-notes/records/'.$id, ['name' => 'central updated'])->assertOk();
        $this->getJson('/frame/resources/central-notes')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(0, BeamParticle::on('shared_b')->count());
        $this->assertSame('central updated', BeamParticle::on('shared_central')->findOrFail($id)->payload['name']);
        $this->actor(99);
        $this->getJson('/central-notes')->assertForbidden();
        $this->getJson('/frame/resources/central-notes')->assertForbidden();
        $this->postJson('/central-notes', ['name' => 'refused'])->assertForbidden();
        $this->postJson('/frame/resources/central-notes', ['name' => 'refused'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/central-notes')->assertForbidden();
        $this->postJson('/central-notes', ['name' => 'guest'])->assertForbidden();
        $this->getJson('/frame/resources/central-notes')->assertUnauthorized();
    }

    public function test_forged_owner_and_schema_are_refused_in_both_transports(): void
    {
        foreach (['/shared-notes', '/central-notes', '/frame/resources/shared-notes', '/frame/resources/central-notes'] as $path) {
            foreach (['owner', 'meta', 'schema_ref', 'schemaRef', 'schema_id', 'id'] as $field) {
                $this->postJson($path, ['name' => 'forged', $field => 'caller-chosen'])->assertUnprocessable();
            }
        }
        $this->assertSame(0, BeamParticle::on('shared_a')->count());
        $this->assertSame(0, BeamParticle::on('shared_central')->count());
    }

    public function test_single_workspace_host_shares_and_split_host_without_tenant_refuses(): void
    {
        $this->tenancy->multi = false;
        $this->tenancy->id = null;
        $id = $this->postJson('/shared-notes', ['name' => 'whole host'])->assertCreated()->json('data.id');
        $this->actor(11);
        $this->getJson('/shared-notes/'.$id)->assertOk();
        $this->assertSame(['type' => 'workspace', 'id' => 'host'], BeamParticle::findOrFail($id)->meta['owner']);
        $this->tenancy->multi = true;
        $this->getJson('/shared-notes')->assertForbidden();
        $this->postJson('/shared-notes', ['name' => 'no tenant'])->assertForbidden();
    }

    public function test_active_tenants_remain_isolated_without_a_resolvable_central_split(): void
    {
        $this->tenancy->multi = false;
        $id = $this->postJson('/shared-notes', ['name' => 'tenant a'])->assertCreated()->json('data.id');
        $this->assertSame(['type' => 'workspace', 'id' => 'tenant:a'], BeamParticle::findOrFail($id)->meta['owner']);
        $this->workspace('b', 'shared_a');
        $this->getJson('/shared-notes/'.$id)->assertNotFound();
        $this->getJson('/frame/resources/shared-notes')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_central_record_reads_with_a_schema_registry_following_the_default_connection(): void
    {
        $schemas = new DatabaseSchemaRegistry;
        $this->app->instance(SchemaRegistry::class, $schemas);
        $this->app->instance(SchemaTargetResolver::class, new RegistrySchemaTargetResolver($schemas));
        $id = $this->postJson('/central-notes', ['name' => 'tenant registry'])->assertCreated()->json('data.id');
        $this->workspace('b', 'shared_b');
        $this->assertSame([], $schemas->versionsFor('https://example.test/shared/central'));
        $this->getJson('/central-notes/'.$id)->assertOk()->assertJsonPath('data.name', 'tenant registry');
        $this->getJson('/frame/resources/central-notes/records/'.$id)->assertOk()->assertJsonPath('data.name', 'tenant registry');
    }
}

class SharedTestTenancy implements TenancyContext
{
    public ?string $id = 'a';

    public bool $multi = true;

    public function isMultiTenant(): bool
    {
        return $this->multi;
    }

    public function isActive(): bool
    {
        return $this->id !== null;
    }

    public function currentTenant(): ?object
    {
        return $this->id === null ? null : (object) ['id' => $this->id];
    }

    public function currentTenantId(): int|string|null
    {
        return $this->id;
    }

    public function centralConnection(): ?string
    {
        return 'shared_central';
    }
}

#[ParticleResource(key: 'shared-notes', backing: WorkspaceSharedParticleBacking::class, input: SharedNoteInput::class,
    label: 'Shared notes', schemaRef: 'https://example.test/shared/notes/1', policy: 'test.shared.manage', readPolicy: SharedParticleAccess::class)]
class SharedNoteRead extends Data
{
    public function __construct(public string $id, public string $name) {}
}

#[ParticleResource(key: 'shared-other', backing: WorkspaceSharedParticleBacking::class, input: SharedNoteInput::class,
    label: 'Other shared notes', schemaRef: 'https://example.test/shared/other/1', policy: 'test.shared.manage', readPolicy: SharedParticleAccess::class)]
class SharedOtherRead extends SharedNoteRead {}

#[ParticleResource(key: 'central-notes', backing: CentralParticleBacking::class, input: SharedNoteInput::class,
    label: 'Central notes', schemaRef: 'https://example.test/shared/central/1', policy: 'test.shared.manage', readPolicy: SharedParticleAccess::class)]
class CentralNoteRead extends SharedNoteRead {}

class SharedNoteInput extends Data
{
    public function __construct(public string $name) {}
}
