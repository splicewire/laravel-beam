<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Collections\CollectionWriter;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\WriteNotAuthorized;

class CollectionWriterTest extends TestCase
{
    private const STEM = 'https://example.test/schemas/articles';

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
            $table->string('schema_ref');
            $table->string('schema_id')->nullable();
            $table->string('migration_status')->nullable();
            $table->string('head_version')->nullable();
            $table->string('source_tier')->default('local');
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        $registry = new DatabaseSchemaRegistry;
        $registry->register([
            '$id' => self::STEM.'/1', 'type' => 'object', 'required' => ['slug'],
            'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'slug'],
            'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true]],
        ]);
        $this->app->instance(SchemaRegistry::class, $registry);
        $this->app->instance(SchemaTargetResolver::class, new RegistrySchemaTargetResolver($registry));
        $this->app->instance(WriteGate::class, new class implements WriteGate
        {
            public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
            {
                return $actor === 'author';
            }
        });
        Event::fake();
    }

    private function record(): BeamParticle
    {
        return new BeamParticle(['schema_ref' => self::STEM.'/1']);
    }

    public function test_authorized_writer_persists_and_emits_after_the_unique_transaction(): void
    {
        $model = app(CollectionWriter::class)->write($this->record(), ['slug' => 'one'], 'author');
        $this->assertTrue($model->exists);
        $this->assertSame(1, DB::table(Beam::table('particles'))->count());
        Event::assertDispatched(BeamParticlePersisted::class);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_unauthorized_duplicate_does_not_expose_the_unique_collision(): void
    {
        app(CollectionWriter::class)->write($this->record(), ['slug' => 'same'], 'author');
        $this->expectException(WriteNotAuthorized::class);
        app(CollectionWriter::class)->write($this->record(), ['slug' => 'same'], null);
    }

    public function test_authorized_duplicate_refuses_without_a_second_event_or_row(): void
    {
        $writer = app(CollectionWriter::class);
        $writer->write($this->record(), ['slug' => 'same'], 'author');
        try {
            $writer->write($this->record(), ['slug' => 'same'], 'author');
            $this->fail('Duplicate accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
        $this->assertSame(1, DB::table(Beam::table('particles'))->count());
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);
    }
}
