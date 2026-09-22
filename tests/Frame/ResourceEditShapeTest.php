<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Tests\TestCase;

class ResourceEditShapeTest extends TestCase
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
        $app['config']->set('beam.core.schema.sources', ['db']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_hooks_table', 'create_beam_schemas_table'] as $stem) {
            (require dirname(__DIR__, 2)."/database/migrations/shared/{$stem}.php.stub")->up();
        }
        Gate::policy(Hook::class, ResourceEditShapePolicy::class);
        Gate::policy(BeamSchema::class, ResourceEditShapePolicy::class);
        $this->actingAs((new User)->forceFill(['id' => 1]));
    }

    public function test_hook_detail_projects_editable_values_without_revealing_credentials(): void
    {
        $hook = Hook::create(['endpoint' => 'https://example.com/hook', 'events' => [], 'secret' => 'private-hmac', 'token' => 'private-bearer', 'paused_at' => now()]);
        $data = $this->getJson("/frame/resources/hooks/records/{$hook->id}")->assertOk()->json('data');
        $this->assertSame($hook->id, $data['id']);
        $this->assertTrue($data['paused']);
        $this->assertSame($hook->endpoint, $data['endpoint']);
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertStringNotContainsString('private-', json_encode($data));
        $this->putJson("/frame/resources/hooks/records/{$hook->id}", ['paused' => false])->assertOk();
        $hook->refresh();
        $this->assertNull($hook->paused_at);
        $this->assertSame('private-hmac', $hook->secret);
        $this->assertSame('private-bearer', $hook->token);
    }

    public function test_the_hook_form_describes_input_instead_of_computed_read_fields(): void
    {
        $properties = $this->getJson('/frame/resources/hooks/schema')->assertOk()->json('properties');
        $this->assertArrayHasKey('paused', $properties);
        $this->assertArrayNotHasKey('secret_preview', $properties);
        $this->assertArrayNotHasKey('deliverable', $properties);
        $this->assertArrayNotHasKey('consecutive_failures', $properties);
    }

    public function test_schemas_offer_artifact_creation_and_refuse_in_place_updates(): void
    {
        $schema = $this->getJson('/frame/resources/schemas/schema')->assertOk()->json();
        $this->assertContains('artifact', $schema['required']);
        $properties = $schema['properties'];
        $this->assertSame(['artifact'], array_keys($properties));
        $this->assertSame('object', $properties['artifact']['type']);
        $this->assertSame('json', $properties['artifact']['x-stud-widget']);
        $artifact = ['$id' => 'https://example.test/schemas/shape/1', 'type' => 'object', 'properties' => []];
        $row = $this->postJson('/frame/resources/schemas', ['artifact' => $artifact])->assertSuccessful()->json('data');
        $this->assertSame($artifact, $row['artifact']);
        $this->getJson('/frame/resources/schemas/records/'.$row['id'])->assertOk()->assertJsonPath('data.artifact', $artifact);
        $this->putJson('/frame/resources/schemas/records/'.$row['id'], ['artifact' => [...$artifact, 'description' => 'changed']])->assertStatus(405);
        $this->assertSame($artifact, BeamSchema::findOrFail($row['id'])->artifact);
    }

    #[DataProvider('invalidSchemaArtifacts')]
    public function test_schema_creation_rejects_artifacts_without_a_nonempty_string_id(array $payload, string $error): void
    {
        $this->postJson('/frame/resources/schemas', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);

        $this->assertSame(0, BeamSchema::query()->count());
    }

    public static function invalidSchemaArtifacts(): iterable
    {
        yield 'missing artifact' => [[], 'artifact'];
        yield 'empty JSON object' => [['artifact' => new \stdClass], 'artifact.$id'];
        yield 'missing id' => [['artifact' => ['type' => 'object']], 'artifact.$id'];
        foreach (['empty' => '', 'whitespace' => '  ', 'null' => null, 'number' => 42, 'boolean' => true, 'array' => ['invalid']] as $case => $id) {
            yield $case.' id' => [['artifact' => ['$id' => $id]], 'artifact.$id'];
        }
    }
}

class ResourceEditShapePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
