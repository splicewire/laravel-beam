<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleAccess;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleBacking;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;

class ActorOwnedParticleTest extends TestCase
{
    private User $owner;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('w', 32));
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
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
        $schemas = new DatabaseSchemaRegistry;
        $this->app->instance(SchemaRegistry::class, $schemas);
        $this->app->instance(SchemaTargetResolver::class, new RegistrySchemaTargetResolver($schemas));
        foreach (['notes', 'tasks'] as $key) {
            $this->app->make(ParticleResourceRegistry::class)->register($this->resource($key));
            Particle::mount($key, $key);
        }
        $this->owner = new User;
        $this->owner->id = 10;
        $this->actingAs($this->owner);
    }

    private function resource(string $key): ParticleResource
    {
        return new ParticleResource(key: $key, backing: ActorOwnedParticleBacking::class,
            data: $key === 'tasks' ? OwnedTaskRead::class : OwnedRead::class, input: $key === 'tasks' ? OwnedTaskInput::class : OwnedInput::class, label: ucfirst($key),
            schemaRef: 'https://example.test/schemas/'.$key.'/1',
            policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class);
    }

    public function test_rest_crud_and_schema_owner_isolation(): void
    {
        $id = $this->postJson('/notes', ['name' => 'mine'])->assertCreated()->assertJsonPath('data.name', 'mine')->json('data.id');
        $record = BeamParticle::findOrFail($id);
        $this->assertSame(['name' => 'mine'], $record->payload);
        $this->assertSame(['type' => $this->owner->getMorphClass(), 'id' => '10'], $record->meta['owner']);
        $this->getJson('/notes')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/tasks')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/frame/resources/notes')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/frame/resources/notes/summary')->assertOk()->assertJsonPath('figures.0.value', 1);
        $this->getJson('/notes/'.$id)->assertOk();
        $this->putJson('/notes/'.$id, ['name' => 'changed'])->assertOk()->assertJsonPath('data.name', 'changed');
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->json($method, '/tasks/'.$id, ['name' => 'forged'])->assertNotFound();
        }
        $other = new User;
        $other->id = 11;
        $this->actingAs($other);
        $this->getJson('/notes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/frame/resources/notes/summary')->assertOk()->assertJsonPath('figures.0.value', 0);
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->json($method, '/notes/'.$id, ['name' => 'forged'])->assertNotFound();
        }
        $this->actingAs($this->owner);
        $this->deleteJson('/notes/'.$id)->assertOk();
        $this->assertDatabaseCount(Beam::table('particles'), 0);
    }

    public function test_invalid_and_forged_envelopes_never_persist(): void
    {
        $this->postJson('/notes', ['name' => []])->assertUnprocessable();
        foreach (['meta', 'schema_ref', 'schema_id', 'owner', 'head_version', 'source_tier', 'id'] as $field) {
            $this->postJson('/notes', ['name' => 'valid', $field => 'forged'])->assertUnprocessable();
        }
        $this->assertDatabaseCount(Beam::table('particles'), 0);
        $this->app['auth']->forgetGuards();
        $this->postJson('/notes', ['name' => 'guest'])->assertForbidden();
        $this->getJson('/notes')->assertForbidden();
    }

    public function test_payload_omission_and_explicit_null_and_camel_case_are_preserved(): void
    {
        $row = $this->postJson('/notes', ['name' => 'one', 'note' => 'keep', 'displayName' => 'Camel'])->assertCreated()->json('data');
        $this->patchJson('/notes/'.$row['id'], ['name' => 'two'])->assertOk()->assertJsonPath('data.note', 'keep');
        $this->patchJson('/notes/'.$row['id'], ['name' => 'three', 'note' => null])->assertOk()->assertJsonPath('data.note', null);
        $payload = BeamParticle::findOrFail($row['id'])->payload;
        $this->assertSame('Camel', $payload['displayName']);
        $this->assertArrayNotHasKey('display_name', $payload);
    }

    public function test_owned_rows_and_collections_are_mutually_invisible(): void
    {
        $id = $this->postJson('/notes', ['name' => 'owned'])->assertCreated()->json('data.id');
        $standalone = app(\Splicewire\Beam\Collections\StandaloneRecordQuery::class)->query();
        $this->assertFalse((clone $standalone)->whereKey($id)->exists());
        $unowned = BeamParticle::create(['schema_ref' => 'https://example.test/schemas/notes', 'payload' => ['name' => 'unowned']]);
        $this->assertTrue((clone $standalone)->whereKey($unowned->id)->exists());
        $this->getJson('/notes/'.$unowned->id)->assertNotFound();
        $this->getJson('/notes')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_a_missing_current_schema_target_refuses_without_a_particle(): void
    {
        $this->app->instance(SchemaTargetResolver::class, new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return [];
            }
        });
        $this->postJson('/notes', ['name' => 'invalid target'])->assertUnprocessable();
        $this->assertDatabaseCount(Beam::table('particles'), 0);
    }

    public function test_an_owned_resource_without_an_explicit_write_policy_denies(): void
    {
        $resource = $this->resource('notes');
        $resource->policy = null;
        app(ParticleResourceRegistry::class)->register($resource);
        $this->postJson('/notes', ['name' => 'not authorized'])->assertForbidden();
        $this->assertDatabaseCount(Beam::table('particles'), 0);
    }

    public function test_the_read_data_class_supplies_the_default_schema_binding(): void
    {
        $resource = new ParticleResource(key: 'derived', backing: ActorOwnedParticleBacking::class,
            data: OwnedRead::class, input: OwnedInput::class, label: 'Derived',
            policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class);
        app(ParticleResourceRegistry::class)->register($resource);
        Particle::mount('derived', 'derived');
        $row = $this->postJson('/derived', ['name' => 'no schemaRef required'])->assertCreated()->json('data');
        $this->assertSame(OwnedRead::class, BeamParticle::findOrFail($row['id'])->schema_ref);
        $this->getJson('/derived/'.$row['id'])->assertOk()->assertJsonPath('data.name', 'no schemaRef required');
    }

    public function test_two_owned_resources_cannot_share_a_derived_schema_binding(): void
    {
        $registry = app(ParticleResourceRegistry::class);
        foreach (['shared-a', 'shared-b'] as $key) {
            $resource = new ParticleResource(key: $key, backing: ActorOwnedParticleBacking::class,
                data: OwnedRead::class, input: OwnedInput::class, label: $key,
                policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class);
            if ($key === 'shared-b') {
                $this->expectException(\InvalidArgumentException::class);
                $this->expectExceptionMessage('Schema binding');
            }
            $registry->register($resource);
        }
    }

    public function test_owned_hook_subscriptions_remain_refused_without_schema_scoped_delivery(): void
    {
        $this->getJson('/notes')->assertOk();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\Splicewire\Beam\Webhooks\HookSubscriptionReach::class)->authorize(['notes.persisted'], null);
    }

    public function test_schema_acceptance_refusal_after_valid_data_never_persists_or_emits(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Splicewire\Beam\Events\BeamParticlePersisted::class]);
        $this->app->instance(SchemaTargetResolver::class, new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return ['$id' => $recordType.'/1', 'type' => 'object', 'properties' => ['name' => ['type' => 'integer']], 'required' => ['name']];
            }
        });
        $this->postJson('/notes', ['name' => 'Data-valid'])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->assertDatabaseCount(Beam::table('particles'), 0);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\Splicewire\Beam\Events\BeamParticlePersisted::class);
    }

    public function test_update_envelope_forgery_and_guest_crud_leave_the_row_unchanged(): void
    {
        $row = $this->postJson('/notes', ['name' => 'keep'])->assertCreated()->json('data');
        $this->assertSame(['id', 'name', 'note', 'displayName'], array_keys($row));
        $id = $row['id'];
        \Illuminate\Support\Facades\Event::fake([\Splicewire\Beam\Events\BeamParticlePersisted::class]);
        foreach (['meta', 'schema_ref', 'schema_id', 'owner', 'head_version', 'source_tier', 'external_ref', 'projector_version'] as $field) {
            $this->putJson('/notes/'.$id, ['name' => 'forged', $field => 'forged'])->assertUnprocessable();
            $this->putJson('/frame/resources/notes/records/'.$id, ['name' => 'forged', $field => 'forged'])->assertUnprocessable();
        }
        $this->putJson('/notes/'.$id, ['name' => []])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson('/notes/'.$id)->assertForbidden();
        $this->putJson('/notes/'.$id, ['name' => 'guest'])->assertNotFound();
        $this->deleteJson('/notes/'.$id)->assertNotFound();
        $this->getJson('/frame/resources/notes')->assertUnauthorized();
        $this->assertSame('keep', BeamParticle::findOrFail($id)->payload['name']);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\Splicewire\Beam\Events\BeamParticlePersisted::class);
    }

    public function test_filter_metadata_uses_the_resource_read_policy_on_the_shared_model(): void
    {
        $resource = $this->resource('private-tasks');
        $resource->readPolicy = RefuseOwnedTaskRead::class;
        app(ParticleResourceRegistry::class)->register($resource);
        $this->getJson('/notes')->assertOk();
        $this->getJson('/frame/resources/private-tasks/filters/schema')->assertForbidden();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\Splicewire\Beam\Filters\ResourceFilters::class)->authorizeModel(BeamParticle::class, $resource);
    }

    public function test_persisted_signal_keeps_schema_identity_without_model_wide_named_delivery(): void
    {
        $named = 0;
        $bindings = [];
        \Illuminate\Support\Facades\Event::listen('tasks.persisted', function () use (&$named): void {
            $named++;
        });
        \Illuminate\Support\Facades\Event::dispatch('tasks.persisted');
        $this->assertSame(1, $named);
        $named = 0;
        \Illuminate\Support\Facades\Event::listen(\Splicewire\Beam\Events\BeamParticlePersisted::class,
            function (\Splicewire\Beam\Events\BeamParticlePersisted $event) use (&$bindings): void {
                $bindings[] = $event->schemaRef;
            });
        $this->postJson('/notes', ['name' => 'schema signal'])->assertCreated();
        $this->assertSame(['https://example.test/schemas/notes'], $bindings);
        $this->assertSame(0, $named);
    }

    public function test_a_host_write_gate_still_narrows_the_declared_owned_policy(): void
    {
        $this->app->instance(\Splicewire\Beam\Write\Contracts\WriteGate::class, new class implements \Splicewire\Beam\Write\Contracts\WriteGate
        {
            public function authorizes(\Illuminate\Database\Eloquent\Model|string $subject, array $payload, mixed $actor = null): bool
            {
                return false;
            }
        });
        $this->postJson('/notes', ['name' => 'host refused'])->assertForbidden();
        $this->assertDatabaseCount(Beam::table('particles'), 0);
    }

    public function test_frame_socket_crud_refuses_foreign_schemas_actors_and_envelope_forgery(): void
    {
        $row = $this->postJson('/frame/resources/notes', ['name' => 'socket'])->assertSuccessful()->json('data');
        $id = $row['id'];
        $this->getJson('/frame/resources/notes/records/'.$id)->assertOk()->assertJsonPath('data.name', 'socket');
        $this->putJson('/frame/resources/notes/records/'.$id, ['name' => 'updated'])->assertOk();
        $this->getJson('/frame/resources/tasks/records/'.$id)->assertNotFound();
        foreach (['PUT', 'DELETE'] as $method) {
            $this->json($method, '/frame/resources/tasks/records/'.$id, ['name' => 'foreign'])->assertNotFound();
        }
        $this->postJson('/frame/resources/notes', ['name' => 'forged', 'meta' => ['owner' => []]])->assertUnprocessable();
        $other = new User;
        $other->id = 11;
        $this->actingAs($other);
        $this->getJson('/frame/resources/notes/records/'.$id)->assertNotFound();
        foreach (['PUT', 'DELETE'] as $method) {
            $this->json($method, '/frame/resources/notes/records/'.$id, ['name' => 'foreign'])->assertNotFound();
        }
        $this->assertSame('updated', BeamParticle::findOrFail($id)->payload['name']);
        $this->actingAs($this->owner)->deleteJson('/frame/resources/notes/records/'.$id)->assertNoContent();
    }

    public function test_frame_uses_bound_actor_not_the_ambient_owner(): void
    {
        $other = new User;
        $other->id = 11;
        $handler = $this->app->make(ParticleFrameResourceHandler::class)->forActor($other);
        $definition = $this->resource('notes')->toResourceDefinition();
        $result = $handler->store($definition, ['name' => 'other']);
        $this->assertSame('other', $result['name']);
        $record = BeamParticle::findOrFail($result['id']);
        $this->assertSame('11', $record->meta['owner']['id']);
        $this->getJson('/notes/'.$record->id)->assertNotFound();
        $this->assertSame('other', $handler->show($definition, $record->id)['name']);
        $updated = $handler->update($definition, $record->id, ['name' => 'updated']);
        $this->assertSame('updated', $updated['name']);
        $handler->destroy($definition, $record->id);
        $this->assertDatabaseCount(Beam::table('particles'), 0);
    }
}

class OwnedRead extends Data
{
    public function __construct(public string $id, public string $name, public ?string $note = null, public ?string $displayName = null) {}
}

class OwnedInput extends Data
{
    public function __construct(public string $name, public string|\Spatie\LaravelData\Optional|null $note = new \Spatie\LaravelData\Optional, public string|\Spatie\LaravelData\Optional $displayName = new \Spatie\LaravelData\Optional) {}
}

class OwnedTaskRead extends OwnedRead {}

class OwnedTaskInput extends OwnedInput {}

class RefuseOwnedTaskRead implements \Splicewire\Beam\Authorization\ResourceReadPolicy
{
    public function inspect(?\Illuminate\Contracts\Auth\Authenticatable $actor, ParticleResource $resource, \Illuminate\Http\Request $request): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Auth\Access\Response::deny('Task metadata is unavailable to this actor.');
    }
}
