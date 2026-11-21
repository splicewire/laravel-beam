<?php

namespace Splicewire\Beam\Tests\Write;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Agreements\NullAssentRecorder;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteRequirement;

class AssentRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('assent_recorder_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Gate::define('create', fn () => true);
        Gate::define('update', fn () => true);
        $this->app->instance(WriteGate::class, new class implements WriteGate
        {
            public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
            {
                return true;
            }
        });
    }

    public function test_default_binding_is_a_no_op(): void
    {
        $this->assertInstanceOf(NullAssentRecorder::class, $this->app->make(AssentRecorder::class));
    }

    public function test_recorder_receives_the_saved_subject_inside_its_connection_transaction(): void
    {
        $recorder = new class implements AssentRecorder
        {
            public array $calls = [];

            public function record(Model $subject, WriteRequirement $requirement): void
            {
                $this->calls[] = [$subject, $requirement, $subject->getConnection()->transactionLevel()];
            }
        };
        $this->app->instance(AssentRecorder::class, $recorder);
        $startingLevel = DB::connection()->transactionLevel();
        $actor = new AssentRecorderSubject;
        $requirement = new WriteRequirement('assent-recorder-subjects:write', actor: $actor);

        $subject = $this->app->make(ParticleWriter::class)->requiring($requirement)->write(
            new AssentRecorderSubject,
            ['name' => 'recorded'],
        );

        $this->assertCount(1, $recorder->calls);
        [$seen, $seenRequirement, $transactionLevel] = $recorder->calls[0];
        $this->assertTrue($seen->is($subject));
        $this->assertTrue($seen->exists);
        $this->assertNotNull($seen->getKey());
        $this->assertSame($requirement, $seenRequirement);
        $this->assertSame($actor, $seenRequirement->actor);
        $this->assertSame($startingLevel + 1, $transactionLevel);
        $this->assertSame($startingLevel, DB::connection()->transactionLevel());
    }

    public function test_a_write_without_a_requirement_does_not_call_the_recorder(): void
    {
        $recorder = new class implements AssentRecorder
        {
            public int $calls = 0;

            public function record(Model $subject, WriteRequirement $requirement): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(AssentRecorder::class, $recorder);

        $this->app->make(ParticleWriter::class)->write(new AssentRecorderSubject, ['name' => 'ordinary']);

        $this->assertSame(0, $recorder->calls);
    }

    public function test_a_recorder_exception_rolls_back_the_subject_write(): void
    {
        $this->app->instance(AssentRecorder::class, new class implements AssentRecorder
        {
            public function record(Model $subject, WriteRequirement $requirement): void
            {
                throw new RuntimeException('recording failed');
            }
        });

        try {
            $this->app->make(ParticleWriter::class)->requiring(new WriteRequirement('assent-recorder-subjects:write'))->write(
                new AssentRecorderSubject,
                ['name' => 'must roll back'],
            );
            $this->fail('The recording error must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('recording failed', $error->getMessage());
        }

        $this->assertSame(0, DB::table('assent_recorder_subjects')->count());
    }
}

class AssentRecorderSubject extends Model
{
    protected $table = 'assent_recorder_subjects';

    protected $guarded = [];
}
