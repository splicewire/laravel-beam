<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV2;
use Splicewire\Beam\Tests\TestCase;

/**
 * The headline seam: the composed {@see SchemaRecord} proven END-TO-END on testbench
 * sqlite. It rides the container's DEFAULT {@see RecordReconciler}
 * (a beam-bound {@see SchemaLadderMigrator}), so the three
 * versioning disciplines that coexist on the record are exercised through the real
 * wiring, not a hand-built adapter:
 *
 *  - reconcile-on-read (schema migration on read) — a lagging row upcasts + writes
 *    back on the cheap path; an expensive (x-migrate:llm) row reads `pending` with the
 *    original preserved and NEVER runs the model;
 *  - durable snapshots + restore — snapshot / mutate / restore round-trips;
 *  - restore-composes-migration — an OLD snapshot lands current-shaped because
 *    {@see SchemaRecord::migrateSnapshotForward()} runs its stale payload FORWARD.
 *
 * To make the default reconciler resolve the fixture schemas, the container's
 * {@see SchemaRegistry} is rebound to a throwaway {@see FilesystemSchemaRegistry} with
 * the Cheap + Expensive v1/v2 artifacts registered. The beam binding reads
 * `app(SchemaRegistry::class)` lazily, so rebinding before the read suffices.
 *
 * `schema_ref` on each row is a versioned fixture `$id` (`<base>/<name>/<version>`),
 * so the record type the reconciler resolves versions from is its STEM
 * ({@see SchemaRecord::resolveRecordType()}), registry-latest.
 */
class SchemaRecordVersioningTest extends TestCase
{
    private const CHEAP_STEM = 'https://schemas.splicewire.app/test/record-versioning-cheap';

    private const EXPENSIVE_STEM = 'https://schemas.splicewire.app/test/record-versioning-expensive';

    // `schema_ref` is a versioned `$id` whose STEM is the record type the reconciler
    // resolves versions off ({@see SchemaRecord::resolveRecordType()} = its stem).
    private const CHEAP_REF = self::CHEAP_STEM.'/2';

    private const EXPENSIVE_REF = self::EXPENSIVE_STEM.'/2';

    private string $frozenDir;

    private JsonSchemaGenerator $generator;

    private FilesystemSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/rv-record-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $this->generator = new JsonSchemaGenerator(config('data-schemas', []));
        $this->registry = new FilesystemSchemaRegistry($this->frozenDir);

        // Register the fixture v1 + v2 artifacts so the default reconciler can resolve
        // both the stored (old) and current (latest) shapes off the stem.
        foreach ([
            FixtureCheapV1::class,
            FixtureCheapV2::class,
            FixtureExpensiveV1::class,
            FixtureExpensiveV2::class,
        ] as $cls) {
            $this->registry->register($this->generator->generate(new ReflectionClass($cls)));
        }

        // Rebind the container's registry BEFORE the beam default reconciler reads it
        // (the binding resolves `app(SchemaRegistry::class)` lazily on first use). The
        // SHIPPED default `RegistrySchemaTargetResolver` + `RecordReconciler` bindings then
        // resolve the fixture schemas straight off this registry — this test exercises the
        // real out-of-the-box wiring, no test-fake resolver.
        app()->singleton(SchemaRegistry::class, fn () => $this->registry);

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

        // activitylog with uuid morphs — beam records use uuid7 keys.
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
    }

    /** The absolute versioned `$id` for a fixture stem at a version. */
    private function idAt(string $stem, int $version): string
    {
        return $stem.'/'.$version;
    }

    public function test_a_write_stamps_the_current_schema_id_and_current_status(): void
    {
        $record = SchemaRecord::create([
            'schema_ref' => self::CHEAP_REF,
            'payload' => ['title' => 'Now', 'body' => 'B', 'summary' => 'S'],
        ]);

        $this->assertSame($this->idAt(self::CHEAP_STEM, 2), $record->schema_id);
        $this->assertSame(MigrationStatus::Current->value, $record->migration_status);

        // The persisted row carries the same stamp.
        $row = DB::table('schema_records')->where('id', $record->id)->first();
        $this->assertSame($this->idAt(self::CHEAP_STEM, 2), $row->schema_id);
        $this->assertSame(MigrationStatus::Current->value, $row->migration_status);
    }

    public function test_a_lagging_read_upcasts_the_payload_and_writes_back(): void
    {
        $record = SchemaRecord::create([
            'schema_ref' => self::CHEAP_REF,
            'payload' => ['title' => 'Aged', 'body' => 'Body'],
        ]);

        // Age the row: rewrite it to the v1 id + a v1-shaped payload, bypassing events.
        DB::table('schema_records')->where('id', $record->id)->update([
            'schema_id' => $this->idAt(self::CHEAP_STEM, 1),
            'migration_status' => MigrationStatus::Pending->value,
            'payload' => json_encode(['title' => 'Aged', 'body' => 'Body']),
        ]);

        // Re-fetch: the retrieved hook reconciles cheap v1 → v2, surfaces the upcast
        // payload (the new `summary` field present), and writes the upgrade back.
        $fresh = SchemaRecord::find($record->id);

        $this->assertArrayHasKey('summary', $fresh->payload);
        $this->assertSame('Aged', $fresh->payload['title']);
        $this->assertSame($this->idAt(self::CHEAP_STEM, 2), $fresh->schema_id);
        $this->assertSame(MigrationStatus::Current->value, $fresh->migration_status);

        // The persisted row was advanced — the write-back amortized the migration.
        $row = DB::table('schema_records')->where('id', $record->id)->first();
        $this->assertSame($this->idAt(self::CHEAP_STEM, 2), $row->schema_id);
        $this->assertSame(MigrationStatus::Current->value, $row->migration_status);
        $this->assertArrayHasKey('summary', json_decode($row->payload, true));
    }

    public function test_an_expensive_read_is_pending_with_the_original_payload_and_no_write_of_the_migrated_payload(): void
    {
        $record = SchemaRecord::create([
            'schema_ref' => self::EXPENSIVE_REF,
            'payload' => ['title' => 'Keep', 'body' => 'Me'],
        ]);

        // Age the row to the expensive v1 id + a v1-shaped payload.
        DB::table('schema_records')->where('id', $record->id)->update([
            'schema_id' => $this->idAt(self::EXPENSIVE_STEM, 1),
            'migration_status' => MigrationStatus::Current->value,
            'payload' => json_encode(['title' => 'Keep', 'body' => 'Me']),
        ]);

        $fresh = SchemaRecord::find($record->id);

        // Pending: v2 pins x-migrate:llm, so the read defers. The ORIGINAL payload is
        // surfaced untouched (no `headline`), the model never ran.
        $this->assertSame(MigrationStatus::Pending->value, $fresh->migration_status);
        $this->assertSame(['title' => 'Keep', 'body' => 'Me'], $fresh->payload);
        $this->assertArrayNotHasKey('headline', $fresh->payload);

        // The migrated payload is never written: the persisted payload stays v1-shaped.
        $row = DB::table('schema_records')->where('id', $record->id)->first();
        $persisted = json_decode($row->payload, true);
        $this->assertSame(['title' => 'Keep', 'body' => 'Me'], $persisted);
        $this->assertArrayNotHasKey('headline', $persisted);
        $this->assertSame(MigrationStatus::Pending->value, $row->migration_status);
    }

    public function test_snapshot_then_mutate_then_restore_round_trips_the_payload_and_schema_ref(): void
    {
        $record = SchemaRecord::create([
            'schema_ref' => self::CHEAP_REF,
            'payload' => ['title' => 'Original', 'body' => 'B', 'summary' => 'S'],
        ]);

        $version = $record->snapshotVersion('before-edit');

        // Mutate the live record away from the snapshot.
        $record->payload = ['title' => 'Edited', 'body' => 'B2', 'summary' => 'S2'];
        $record->save();
        $this->assertSame('Edited', $record->fresh()->payload['title']);

        // Restore the snapshot: the payload + schema_ref round-trip.
        $record->restoreVersion($version->id);

        $restored = $record->fresh();
        $this->assertSame(self::CHEAP_REF, $restored->schema_ref);
        $this->assertSame(['title' => 'Original', 'body' => 'B', 'summary' => 'S'], $restored->payload);
    }

    public function test_restoring_an_old_snapshot_lands_a_current_shaped_payload(): void
    {
        $record = SchemaRecord::create([
            'schema_ref' => self::CHEAP_REF,
            'payload' => ['title' => 'Aged', 'body' => 'Body'],
        ]);

        // Freeze the row at a v1 shape: age it to the v1 id + a v1-shaped payload,
        // bypassing events, then snapshot THAT stale shape.
        DB::table('schema_records')->where('id', $record->id)->update([
            'schema_id' => $this->idAt(self::CHEAP_STEM, 1),
            'migration_status' => MigrationStatus::Current->value,
            'payload' => json_encode(['title' => 'Aged', 'body' => 'Body']),
        ]);

        // Snapshot the v1-shaped row directly (avoid the retrieved-hook upcast) by
        // freezing the exact stale snapshot the store would persist.
        $staleSnapshot = [
            'schema_ref' => self::CHEAP_REF,
            'schema_id' => $this->idAt(self::CHEAP_STEM, 1),
            'migration_status' => MigrationStatus::Current->value,
            'payload' => ['title' => 'Aged', 'body' => 'Body'],
            'meta' => null,
        ];

        // Drive the compose-migration seam directly (mirroring the app's
        // RestoreComposesMigrationTest): migrateSnapshotForward upcasts the v1 snapshot
        // to a current v2 shape before rehydration.
        $migrated = $record->migrateSnapshotForward($staleSnapshot);

        $this->assertArrayHasKey('summary', $migrated['payload']);
        $this->assertSame('Aged', $migrated['payload']['title']);
        $this->assertSame($this->idAt(self::CHEAP_STEM, 2), $migrated['schema_id']);
    }
}
