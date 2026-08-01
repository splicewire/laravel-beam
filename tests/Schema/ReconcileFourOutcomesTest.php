<?php

namespace Splicewire\Beam\Tests\Schema;

use ReflectionClass;
use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV2;
use Splicewire\Beam\Tests\TestCase;

/**
 * The ONE new seam — the {@see SchemaLadderMigrator} adapter driven through the
 * substrate's reconcile-on-read protocol over a throwaway `$id`-versioned fixture
 * record type (a v1 frozen artifact + a v2 current shape). Assertions live at the
 * PORT BOUNDARY only — the returned `MigrationOutcome` (which of current / migrated / pending
 * / failed, the resulting version id, the preserved payload, the write-back
 * decision) — never the ladder's rung internals or any trait plumbing. The real
 * `MigrationLadder` runs underneath; the adapter gets no separate seam.
 *
 * No database: the migrator resolves the OLD schema from a throwaway filesystem
 * registry and projects the current shape from the live fixture class, so the seam
 * is exercised in isolation.
 */
class ReconcileFourOutcomesTest extends TestCase
{
    private string $frozenDir;

    private JsonSchemaGenerator $generator;

    private FilesystemSchemaRegistry $registry;

    private SchemaLadderMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/rv-reconcile-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $this->generator = new JsonSchemaGenerator(config('data-schemas', []));
        $this->registry = new FilesystemSchemaRegistry($this->frozenDir);

        // Freeze the v1 fixtures the aged records are written under. NOTE: the cheap v1
        // is deliberately NOT frozen here — a test registers it to prove the migrated
        // path, and the missing-artifact test relies on its absence.
        foreach ([FixtureExpensiveV1::class, FixtureUnmigratableV1::class] as $v1) {
            $this->registry->register($this->generator->generate(new ReflectionClass($v1)));
        }

        $this->migrator = new SchemaLadderMigrator($this->registry, $this->generator);
    }

    protected function tearDown(): void
    {
        if (isset($this->frozenDir) && is_dir($this->frozenDir)) {
            array_map('unlink', glob($this->frozenDir.'/*') ?: []);
            @rmdir($this->frozenDir);
        }

        parent::tearDown();
    }

    public function test_resolves_a_record_already_at_the_current_version_as_current_no_work_no_write_back(): void
    {
        $currentId = $this->migrator->currentId(FixtureCheapV2::class);

        $outcome = $this->migrator->reconcile(
            ['title' => 'Now', 'summary' => 'S'],
            $currentId,
            FixtureCheapV2::class,
        );

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($currentId, $outcome->versionId);
        $this->assertNull($outcome->payload);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_migrates_a_v1_record_forward_with_cheap_rungs_only_and_signals_write_back(): void
    {
        // Freeze the cheap v1 so the OLD schema is resolvable to diff against.
        $this->registry->register($this->generator->generate(new ReflectionClass(FixtureCheapV1::class)));

        $currentId = $this->migrator->currentId(FixtureCheapV2::class);
        $v1Id = SchemaId::from($currentId)->withVersion(1);

        $outcome = $this->migrator->reconcile(
            ['title' => 'Aged', 'body' => 'Body'],
            (string) $v1Id,
            FixtureCheapV2::class,
        );

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertTrue($outcome->shouldWriteBack);
        $this->assertSame($currentId, $outcome->versionId);
        $this->assertArrayHasKey('summary', $outcome->payload);
        $this->assertSame('Aged', $outcome->payload['title']);
    }

    public function test_reads_a_record_needing_an_expensive_rung_as_pending_without_running_it(): void
    {
        $currentId = $this->migrator->currentId(FixtureExpensiveV2::class);
        $v1Id = (string) SchemaId::from($currentId)->withVersion(1);
        $original = ['title' => 'Keep', 'body' => 'Me'];

        $outcome = $this->migrator->reconcile($original, $v1Id, FixtureExpensiveV2::class);

        // Pending: the ORIGINAL payload is surfaced untouched, no expensive rung ran.
        $this->assertSame(MigrationStatus::Pending, $outcome->status);
        $this->assertSame($original, $outcome->payload);
        $this->assertArrayNotHasKey('headline', $outcome->payload);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertTrue($outcome->shouldWriteBack);
    }

    public function test_marks_an_unmigratable_record_failed_with_the_original_payload_preserved_immutably(): void
    {
        $currentId = $this->migrator->currentId(FixtureUnmigratableV2::class);
        $v1Id = (string) SchemaId::from($currentId)->withVersion(1);
        $original = ['title' => 'Only Title'];

        $outcome = $this->migrator->reconcile($original, $v1Id, FixtureUnmigratableV2::class);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($original, $outcome->payload);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertTrue($outcome->shouldWriteBack);
    }

    public function test_treats_a_stored_version_newer_than_current_a_downgrade_as_a_non_event(): void
    {
        $currentId = $this->migrator->currentId(FixtureCheapV2::class);
        // A record forward-versioned past current (v3): never auto-migrated backward.
        $newerId = (string) SchemaId::from($currentId)->withVersion(3);

        $outcome = $this->migrator->reconcile(
            ['title' => 'Ahead', 'summary' => 'S'],
            $newerId,
            FixtureCheapV2::class,
        );

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($newerId, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_treats_a_cross_stem_stored_version_as_not_comparable_a_non_event_never_mangled(): void
    {
        $foreignId = 'https://schemas.splicewire.app/test/record-versioning-unrelated/1';

        $outcome = $this->migrator->reconcile(
            ['title' => 'Foreign'],
            $foreignId,
            FixtureCheapV2::class,
        );

        // Not comparable across stems: kept as-is, no migration, no write-back.
        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($foreignId, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_marks_a_record_whose_old_artifact_is_missing_from_the_registry_as_failed(): void
    {
        // The cheap v1 was deliberately NOT frozen in setUp: its artifact cannot be
        // resolved to diff against, so the record cannot be migrated deterministically.
        $currentId = $this->migrator->currentId(FixtureCheapV2::class);
        $v1Id = (string) SchemaId::from($currentId)->withVersion(1);
        $original = ['title' => 'Orphan', 'body' => 'B'];

        $outcome = $this->migrator->reconcile($original, $v1Id, FixtureCheapV2::class);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($original, $outcome->payload);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertTrue($outcome->shouldWriteBack);
    }
}
