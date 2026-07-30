<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Write;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Events\SchemaRecordPersisted;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\PayloadRejected;
use Splicewire\Beam\Write\RecordWriter;
use Splicewire\Beam\Write\WriteNotAuthorized;

/**
 * The primary write-pipeline seam (beam-write-pipeline ticket 03). Every assertion drives the ONE
 * {@see RecordWriter} entry point and observes only what a caller observes — a record persisted or
 * refused, an event emitted — never the pipeline's internal step ordering (DESIGN "Testing Decisions").
 *
 * It is exercised against BOTH a base {@see SchemaRecord} (schema-validated: its content lives in the
 * `payload` column and is checked against its registered target schema) AND a representative app model
 * (its own columns; no registered schema, so validation is a no-op) — proving the writer is genuinely
 * model-agnostic.
 */
class RecordWriterTest extends TestCase
{
    // The stem the Cheap fixtures actually generate ({@see FixtureCheapV1::schemaName()} = the
    // `record-versioning-cheap` name under the generator's base), so the registry resolves the target.
    private const CHEAP_STEM = 'https://schemas.splicewire.app/test/record-versioning-cheap';

    // A versioned `$id`; its STEM is the record type the writer resolves the target schema off.
    private const CHEAP_REF = self::CHEAP_STEM.'/2';

    private string $frozenDir;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway registry carrying the Cheap v1/v2 fixture schemas, rebound onto the container so
        // the shipped default target resolver resolves them for the SchemaRecord validation path.
        $this->frozenDir = sys_get_temp_dir().'/rw-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $generator = new JsonSchemaGenerator(config('data-schemas', []));
        $registry = new FilesystemSchemaRegistry($this->frozenDir);
        foreach ([FixtureCheapV1::class, FixtureCheapV2::class] as $cls) {
            $registry->register($generator->generate(new ReflectionClass($cls)));
        }
        $this->app->singleton(SchemaRegistry::class, fn () => $registry);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->frozenDir) && is_dir($this->frozenDir)) {
            array_map('unlink', glob($this->frozenDir.'/*') ?: []);
            @rmdir($this->frozenDir);
        }

        parent::tearDown();
    }

    public function test_a_conforming_payload_persists_and_emits_the_event(): void
    {
        $this->allowWrites();
        Event::fake([SchemaRecordPersisted::class]);

        // The Cheap v2 target requires every declared property present (nullable ones may be null).
        $payload = ['title' => 'Hello', 'body' => 'World', 'summary' => null];

        $record = $this->writer()->write(new SchemaRecord(['schema_ref' => self::CHEAP_REF]), $payload);

        // Persisted, with the content in the payload column.
        $this->assertTrue($record->exists);
        $this->assertDatabaseHas('schema_records', ['id' => $record->id, 'schema_ref' => self::CHEAP_REF]);
        $this->assertSame($payload, SchemaRecord::findOrFail($record->id)->payload);

        // One post-persist signal, carrying the record, the payload, and the binding.
        Event::assertDispatched(SchemaRecordPersisted::class, function (SchemaRecordPersisted $e) use ($record, $payload) {
            return $e->record->is($record)
                && $e->payload === $payload
                && $e->schemaRef === self::CHEAP_REF;
        });
    }

    public function test_a_non_conforming_payload_is_refused_and_nothing_persists(): void
    {
        $this->allowWrites();
        Event::fake([SchemaRecordPersisted::class]);

        try {
            // The Cheap v2 target requires `title`; omitting it must fail the acceptance gate.
            $this->writer()->write(new SchemaRecord(['schema_ref' => self::CHEAP_REF]), ['body' => 'no title']);
            $this->fail('expected the acceptance gate to reject a non-conforming payload');
        } catch (PayloadRejected) {
            // expected
        }

        $this->assertDatabaseCount('schema_records', 0);
        Event::assertNotDispatched(SchemaRecordPersisted::class);
    }

    public function test_a_write_with_no_binding_is_refused_deny_by_default(): void
    {
        // No Gate ability/policy declared for the record type — deny-by-default must refuse it.
        Event::fake([SchemaRecordPersisted::class]);

        try {
            $this->writer()->write(new AppRecordFixture, ['title' => 'nope']);
            $this->fail('expected deny-by-default to refuse a write with no explicit binding');
        } catch (WriteNotAuthorized) {
            // expected
        }

        $this->assertDatabaseCount('app_records', 0);
        Event::assertNotDispatched(SchemaRecordPersisted::class);
    }

    public function test_a_policy_permitted_write_succeeds_via_the_delegated_gate(): void
    {
        // The default WriteGate delegates to the Laravel gate; a granted ability lets the write through.
        $this->allowWrites();
        Event::fake([SchemaRecordPersisted::class]);

        $record = $this->writer()->write(new AppRecordFixture, ['title' => 'Fragmentish', 'body' => 'B']);

        $this->assertTrue($record->exists);
        $this->assertDatabaseHas('app_records', ['id' => $record->id, 'title' => 'Fragmentish', 'body' => 'B']);
    }

    public function test_the_writer_is_model_agnostic_across_app_model_and_base_record(): void
    {
        $this->allowWrites();

        // A plain app model — its own columns, no registered schema (validation skipped), null binding.
        $app = $this->writer()->write(new AppRecordFixture, ['title' => 'App', 'body' => 'row']);
        $this->assertDatabaseHas('app_records', ['id' => $app->id, 'title' => 'App']);

        // The base SchemaRecord — schema-validated content in the payload column, versioned binding.
        $content = ['title' => 'Rec', 'body' => null, 'summary' => null];
        $record = $this->writer()->write(new SchemaRecord(['schema_ref' => self::CHEAP_REF]), $content);
        $this->assertDatabaseHas('schema_records', ['id' => $record->id, 'schema_ref' => self::CHEAP_REF]);
        $this->assertSame($content, SchemaRecord::findOrFail($record->id)->payload);
    }

    public function test_the_after_persist_hook_runs_with_the_persisted_model(): void
    {
        $this->allowWrites();

        $seen = null;
        $record = $this->writer()->write(
            new AppRecordFixture,
            ['title' => 'Hooked'],
            after: function (Model $model) use (&$seen) {
                $seen = $model;
            },
        );

        $this->assertNotNull($seen, 'the after-persist hook should have run');
        $this->assertTrue($seen->is($record));
        $this->assertTrue($seen->exists, 'the hook runs AFTER the save, on the persisted model');
    }

    /** Resolve a fresh writer (after any Event::fake / Gate::define) so it binds the current dispatcher. */
    private function writer(): RecordWriter
    {
        return $this->app->make(RecordWriter::class);
    }

    /** Grant the create/update abilities so the delegated Laravel gate permits the write. */
    private function allowWrites(): void
    {
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    private function createTables(): void
    {
        Schema::create('schema_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->uuid('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Schema::create('app_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->string('body')->nullable();
            $table->timestamps();
        });
    }
}

/**
 * A representative app model riding the pipeline: it uses {@see PersistsSchemaRecord} on its OWN table
 * with its OWN columns and carries no `schema_ref`, so the writer resolves no target schema for it
 * (validation is a no-op) and its payload fills its attributes directly.
 */
class AppRecordFixture extends Model
{
    use PersistsSchemaRecord;

    protected $table = 'app_records';

    protected $guarded = [];
}
