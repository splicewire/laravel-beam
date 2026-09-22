<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\WriteNotAuthorized;

/** One writable declaration, exercised through Frame and REST using the shipped writer chain. */
class ParticleWriteResultTest extends TestCase
{
    private ParticleResource $resource;

    private bool $allowWrites = true;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('frame_write_records', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('dedupe_key')->nullable()->index();
            $table->timestamps();
        });
        $this->app->instance(SchemaTargetResolver::class, new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string']],
                    'required' => ['email'],
                    'x-beam-dedupe' => ['by' => ['email'], 'mode' => 'ignore'],
                ];
            }
        });
        foreach (['create', 'update', 'frame-write'] as $ability) {
            Gate::define($ability, fn (?Authenticatable $user): bool => $this->allowWrites);
        }
        $this->resource = new ParticleResource(
            key: 'frame-write-records',
            backing: FrameWriteRecord::class,
            data: FrameWriteData::class,
            input: FrameWriteInput::class,
            label: 'Frame write records',
            policy: 'frame-write',
        );
        $this->app->make(ParticleResourceRegistry::class)->register($this->resource);
        Particle::mount('frame-write-records', 'frame-write-records')->only(['store', 'update']);
        Event::fake([BeamParticlePersisted::class]);
    }

    public function test_frame_create_returns_the_persisted_identity_when_dedupe_ignores_a_repeat(): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->resource->toResourceDefinition();
        $first = $handler->store($definition, ['email' => 'ada@example.test']);
        $repeat = $handler->store($definition, ['email' => 'ada@example.test']);

        $this->assertNotNull($first['id']);
        $this->assertSame($first, $repeat);
        $this->assertDatabaseCount('frame_write_records', 1);
        $this->assertDatabaseHas('frame_write_records', ['id' => $repeat['id'], 'email' => 'ada@example.test']);
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);
    }

    public function test_frame_update_projects_the_matched_record_instead_of_refreshing_the_original(): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->resource->toResourceDefinition();
        $first = $handler->store($definition, ['email' => 'ada@example.test']);
        $other = $handler->store($definition, ['email' => 'grace@example.test']);
        $matched = $handler->update($definition, (string) $other['id'], ['email' => 'ada@example.test']);

        $this->assertSame($first, $matched);
        $this->assertDatabaseCount('frame_write_records', 2);
        $this->assertDatabaseHas('frame_write_records', ['id' => $other['id'], 'email' => 'grace@example.test']);
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 2);
    }

    public function test_rest_create_and_update_keep_the_same_dedupe_return_contract(): void
    {
        $first = $this->postJson('/frame-write-records', ['email' => 'ada@example.test'])->assertCreated()->json('data');
        $repeat = $this->postJson('/frame-write-records', ['email' => 'ada@example.test'])->assertCreated()->json('data');
        $this->assertSame($first, $repeat);
        $this->assertNotNull($first['id']);
        $this->assertDatabaseCount('frame_write_records', 1);
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);

        $other = $this->postJson('/frame-write-records', ['email' => 'grace@example.test'])->assertCreated()->json('data');
        $matched = $this->patchJson('/frame-write-records/'.$other['id'], ['email' => 'ada@example.test'])->assertOk()->json('data');
        $this->assertSame($first, $matched);
        $this->assertDatabaseCount('frame_write_records', 2);
        $this->assertDatabaseHas('frame_write_records', ['id' => $other['id'], 'email' => 'grace@example.test']);
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 2);
    }

    #[DataProvider('writeModes')]
    public function test_a_refused_frame_after_hook_rolls_back_the_write(bool $update): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->resource->toResourceDefinition();
        $original = $update ? $handler->store($definition, ['email' => 'ada@example.test']) : null;
        $this->resource->afterWrite = function (Model $model): void {
            throw WriteNotAuthorized::for($model);
        };

        try {
            if ($update) {
                $handler->update($definition, (string) $original['id'], ['email' => 'changed@example.test']);
            } else {
                $handler->store($definition, ['email' => 'changed@example.test']);
            }
            $this->fail('The after-write refusal must reach the caller.');
        } catch (WriteNotAuthorized) {
            $this->assertDatabaseCount('frame_write_records', $update ? 1 : 0);
            $this->assertDatabaseMissing('frame_write_records', ['email' => 'changed@example.test']);
            if ($update) {
                $this->assertDatabaseHas('frame_write_records', $original);
            }
            Event::assertDispatchedTimes(BeamParticlePersisted::class, $update ? 1 : 0);
        }
    }

    #[DataProvider('writeModes')]
    public function test_a_denied_frame_write_does_not_bypass_authorization_when_the_key_matches(bool $update): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->resource->toResourceDefinition();
        $first = $handler->store($definition, ['email' => 'ada@example.test']);
        $this->allowWrites = false;

        try {
            if ($update) {
                $handler->update($definition, (string) $first['id'], ['email' => 'ada@example.test']);
            } else {
                $handler->store($definition, ['email' => 'ada@example.test']);
            }
            $this->fail('Authorization must run before returning a dedupe match.');
        } catch (WriteNotAuthorized) {
            $this->assertDatabaseCount('frame_write_records', 1);
            $this->assertDatabaseHas('frame_write_records', $first);
            Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);
        }
    }

    public static function writeModes(): array
    {
        return ['create' => [false], 'update' => [true]];
    }
}

class FrameWriteRecord extends Model
{
    use Deduplicates;

    protected $table = 'frame_write_records';

    protected $guarded = [];

    public function recordType(): string
    {
        return 'frame-write-record';
    }
}

class FrameWriteData extends BeamData
{
    public function __construct(public ?int $id, public ?string $email) {}
}

class FrameWriteInput extends BeamData
{
    public function __construct(public string $email) {}
}
