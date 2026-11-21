<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\SuppliesParticleWriter;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Tests\Support\AssentRecorderSpy;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteNotAuthorized;
use Splicewire\Beam\Write\WriteRequirement;

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

    public function test_both_transports_use_a_backings_writer_and_preserve_the_gate(): void
    {
        // Re-register via the constructor: runtime declarations own their backing identity.
        $resource = new ParticleResource(key: 'supplied-records', backing: SupplyingWriteBacking::class, data: FrameWriteData::class, input: FrameWriteInput::class, label: 'Supplied records', policy: 'frame-write');
        $this->app->make(ParticleResourceRegistry::class)->register($resource);
        Particle::mount('supplied-records', 'supplied-records')->only(['store']);
        SupplyingWriteBacking::$calls = 0;
        $frame = $this->app->make(ParticleFrameResourceHandler::class)->store($resource->toResourceDefinition(), ['email' => 'frame@example.test']);
        $rest = $this->postJson('/supplied-records', ['email' => 'rest@example.test'])->assertCreated()->json('data');
        $this->assertSame('frame@example.test', $frame['email']);
        $this->assertSame('rest@example.test', $rest['email']);
        $this->assertSame(2, SupplyingWriteBacking::$calls);
        $this->allowWrites = false;
        try {
            $this->app->make(ParticleFrameResourceHandler::class)->store($resource->toResourceDefinition(), ['email' => 'denied-frame@example.test']);
            $this->fail('The supplied writer bypassed the Frame gate.');
        } catch (WriteNotAuthorized) {
            $this->assertDatabaseCount('frame_write_records', 2);
        }
        $this->postJson('/supplied-records', ['email' => 'denied-rest@example.test'])->assertForbidden();
        $this->assertDatabaseCount('frame_write_records', 2);
    }

    public function test_manifest_only_store_keeps_its_model_construction_fallback(): void
    {
        $definition = clone $this->resource->toResourceDefinition();
        $definition->key = 'manifest-only';
        $created = $this->app->make(ParticleFrameResourceHandler::class)->store($definition, ['email' => 'manifest@example.test']);
        $this->assertSame('manifest@example.test', $created['email']);
        $this->assertNotNull($created['id']);
        $this->assertDatabaseHas('frame_write_records', ['id' => $created['id'], 'email' => 'manifest@example.test']);
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

    public function test_rest_and_frame_create_and_update_record_the_saved_subject_before_after_write(): void
    {
        $recorder = new AssentRecorderSpy;
        $this->app->instance(AssentRecorder::class, $recorder);
        $this->resource->afterWrite = function (Model $model) use ($recorder): void {
            $this->assertTrue($model->exists);
            $recorder->order[] = 'after';
        };
        $startingLevel = \Illuminate\Support\Facades\DB::connection()->transactionLevel();

        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $frameCreated = $handler->store($this->resource->toResourceDefinition(), ['email' => 'frame-created@example.test']);
        $handler->update($this->resource->toResourceDefinition(), (string) $frameCreated['id'], ['email' => 'frame-updated@example.test']);
        $restCreated = $this->postJson('/frame-write-records', ['email' => 'rest-created@example.test'])->assertCreated()->json('data');
        $this->patchJson('/frame-write-records/'.$restCreated['id'], ['email' => 'rest-updated@example.test'])->assertOk();

        $this->assertCount(4, $recorder->calls);
        $this->assertSame(['record', 'after', 'record', 'after', 'record', 'after', 'record', 'after'], $recorder->order);
        foreach ($recorder->calls as $call) {
            $this->assertTrue($call['subject']->exists);
            $this->assertNotNull($call['subject']->getKey());
            $this->assertSame($startingLevel + 1, $call['transactionLevel']);
            $this->assertSame('frame-write-records:write', $call['requirement']->behavior);
        }
        $this->assertSame((string) $frameCreated['id'], (string) $recorder->calls[0]['subject']->getKey());
        $this->assertSame((string) $restCreated['id'], (string) $recorder->calls[2]['subject']->getKey());
    }

    #[DataProvider('frameWriteFailures')]
    public function test_a_frame_write_failure_rolls_back_the_write(bool $update, string $failure): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->resource->toResourceDefinition();
        $original = $update ? $handler->store($definition, ['email' => 'ada@example.test']) : null;

        if ($failure === 'recorder') {
            $this->app->instance(AssentRecorder::class, new class implements AssentRecorder
            {
                public function record(Model $subject, WriteRequirement $requirement): void
                {
                    throw new RuntimeException('recording failed');
                }
            });
        } else {
            $this->resource->afterWrite = function (Model $model): void {
                throw WriteNotAuthorized::for($model);
            };
        }

        try {
            if ($update) {
                $handler->update($definition, (string) $original['id'], ['email' => 'changed@example.test']);
            } else {
                $handler->store($definition, ['email' => 'changed@example.test']);
            }
            $this->fail('The frame write failure must reach the caller.');
        } catch (RuntimeException|WriteNotAuthorized $error) {
            if ($failure === 'recorder') {
                $this->assertSame('recording failed', $error->getMessage());
            } else {
                $this->assertInstanceOf(WriteNotAuthorized::class, $error);
            }
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

    public static function frameWriteFailures(): array
    {
        return [
            'create after hook' => [false, 'after'],
            'update after hook' => [true, 'after'],
            'create recorder' => [false, 'recorder'],
            'update recorder' => [true, 'recorder'],
        ];
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

class SupplyingWriteBacking extends EloquentBacking implements SuppliesParticleWriter
{
    public static int $calls = 0;

    public function __construct()
    {
        parent::__construct(FrameWriteRecord::class);
    }

    public function writer(WriteGate $gate): ParticleWriter
    {
        self::$calls++;

        return app()->makeWith(ParticleWriter::class, ['gate' => $gate]);
    }
}
