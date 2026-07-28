<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema;

use ReflectionClass;
use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Schema\Fakes\FakeSchemaTargetResolver;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureSlugTransform;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureTransformV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureTransformV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV2;
use Splicewire\Beam\Tests\TestCase;

/**
 * Extends the four-outcomes fixture harness to the two remaining non-LLM protocol
 * paths, asserted at the port boundary (the returned `MigrationOutcome`), never the ladder
 * internals:
 *
 *  - read-at-version as a PURE view: validate against a deliberately pinned older
 *    version, report current / failed, and NEVER write back;
 *  - the EAGER drain: run the full ladder (cheap + custom-transform rungs; LLM off)
 *    to complete a `pending` record — the upgraded payload + advanced version id +
 *    write-back the caller persists — and quarantine an unmigratable one as failed.
 *
 * DB-free: a throwaway filesystem registry supplies the old + pinned artifacts.
 */
class EagerDrainAndReadAtVersionTest extends TestCase
{
    private string $frozenDir;

    private JsonSchemaGenerator $generator;

    private FilesystemSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/rv-drain-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $this->generator = new JsonSchemaGenerator(config('data-schemas', []));
        $this->registry = new FilesystemSchemaRegistry($this->frozenDir);

        // Freeze the artifacts the drain diffs against + the read-at-version pinned pair.
        foreach ([
            FixtureCheapV1::class,
            FixtureCheapV2::class,
            FixtureTransformV1::class,
            FixtureUnmigratableV1::class,
        ] as $cls) {
            $this->registry->register($this->generator->generate(new ReflectionClass($cls)));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->frozenDir) && is_dir($this->frozenDir)) {
            array_map('unlink', glob($this->frozenDir.'/*') ?: []);
            @rmdir($this->frozenDir);
        }

        parent::tearDown();
    }

    // --- read-at-version: a pure, non-mutating view -----------------------------

    public function test_reads_a_record_under_a_deliberately_pinned_older_version_without_upgrading_it(): void
    {
        $migrator = new SchemaLadderMigrator(
            $this->registry,
            $this->generator,
            targetResolver: new FakeSchemaTargetResolver($this->generator, $this->registry),
        );

        $v1Id = (string) SchemaId::from($migrator->currentId(FixtureCheapV2::class))->withVersion(1);
        $original = ['title' => 'Aged', 'body' => 'Body'];

        $outcome = $migrator->readAtVersion($original, FixtureCheapV2::class, 1);

        // Conforms under v1 — served as-is at the v1 id, and a PURE view: no write-back.
        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_reads_at_a_version_the_payload_does_not_satisfy_as_failed_without_writing_back(): void
    {
        $migrator = new SchemaLadderMigrator(
            $this->registry,
            $this->generator,
            targetResolver: new FakeSchemaTargetResolver($this->generator, $this->registry),
        );

        // A payload MISSING the required `title`, read pinned at v2: it does not validate
        // against v2, so it reads failed — but nothing is written back.
        $broken = ['body' => 'No title here'];

        $outcome = $migrator->readAtVersion($broken, FixtureCheapV2::class, 2);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($broken, $outcome->payload);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    // --- eager drain: the full ladder completes a deferred record ---------------

    public function test_drains_a_pending_record_via_a_custom_transform_rung_upgraded_payload_version_advanced(): void
    {
        $transforms = (new TransformRegistry)->registerName(FixtureSlugTransform::class, new FixtureSlugTransform);
        $migrator = new SchemaLadderMigrator($this->registry, $this->generator, transforms: $transforms);

        $currentId = $migrator->currentId(FixtureTransformV2::class);
        $v1Id = (string) SchemaId::from($currentId)->withVersion(1);

        $outcome = $migrator->reconcileEager(
            ['title' => 'My Post', 'body' => 'B'],
            $v1Id,
            FixtureTransformV2::class,
        );

        // Completed: the transform filled the slug the cheap ladder could not, the
        // candidate conforms, the record advances to v2 and is written back.
        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertTrue($outcome->shouldWriteBack);
        $this->assertSame($currentId, $outcome->versionId);
        $this->assertArrayHasKey('slug', $outcome->payload);
        $this->assertSame('my-post', $outcome->payload['slug']);
    }

    public function test_quarantines_an_unmigratable_record_on_the_eager_drain_as_failed_with_the_original_intact(): void
    {
        // No transform is registered for the unmigratable stem, so even the full ladder
        // cannot fill the required slug — the drain quarantines rather than destroys.
        $migrator = new SchemaLadderMigrator($this->registry, $this->generator, transforms: new TransformRegistry);

        $currentId = $migrator->currentId(FixtureUnmigratableV2::class);
        $v1Id = (string) SchemaId::from($currentId)->withVersion(1);
        $original = ['title' => 'Only Title'];

        $outcome = $migrator->reconcileEager($original, $v1Id, FixtureUnmigratableV2::class);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($original, $outcome->payload);
        $this->assertSame($v1Id, $outcome->versionId);
    }
}
