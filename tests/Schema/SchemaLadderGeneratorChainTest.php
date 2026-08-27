<?php

namespace Splicewire\Beam\Tests\Schema;

use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\ChainedGenerator;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Fixtures\NarrowFixtureGenerator;
use Splicewire\Beam\Tests\Fixtures\RefusingFixtureGenerator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV2;
use Splicewire\Beam\Tests\TestCase;

/**
 * The migrate-on-read adapter resolves its target through the host's configured generator CHAIN.
 *
 * `SchemaLadderMigrator::$generator` was typed as the CONCRETE `JsonSchemaGenerator`, which made a
 * `ChainedGenerator` unpassable — and that is why `BeamServiceProvider` was still hand-building one
 * for the default `RecordReconciler` binding. `data-schemas.generators` is a LIST and the dispatch
 * rule lives only inside `ChainedGenerator`, so at a multi-generator host the binding picked a member
 * the host does not consider authoritative for that class.
 *
 * The stakes here are not a missing document. This is the target schema a stored `BeamParticle`
 * payload is DIFFED AGAINST and then MIGRATED INTO — the wrong generator means silently wrong
 * persisted data, not a gap in a spec. Hence the two halves under test: the chain's own dispatch
 * decides which member describes a class, and a chain that refuses a class yields NO TARGET rather
 * than a throw or a guess.
 */
class SchemaLadderGeneratorChainTest extends TestCase
{
    /** The fixtures are `SchemaIdentity` classes, so this test app has to serve its own schemas. */
    protected function schemaAuthority(): string|bool|null
    {
        return self::SCHEMA_AUTHORITY;
    }

    private string $frozenDir;

    private FilesystemSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/beam-ladder-chain-'.getmypid().'-'.spl_object_id($this);
        @mkdir($this->frozenDir, 0775, true);

        $this->registry = new FilesystemSchemaRegistry($this->frozenDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->frozenDir) && is_dir($this->frozenDir)) {
            array_map('unlink', glob($this->frozenDir.'/*') ?: []);
            @rmdir($this->frozenDir);
        }

        parent::tearDown();
    }

    /** The thingsontv shape: a narrow member FIRST, the ordinary generator behind it. */
    private function narrowFirst(string $accepts): SchemaLadderMigrator
    {
        return new SchemaLadderMigrator($this->registry, new ChainedGenerator([
            new NarrowFixtureGenerator([], $accepts),
            new JsonSchemaGenerator((array) config('data-schemas', [])),
        ]));
    }

    public function test_the_target_schema_comes_from_the_narrow_generator_configured_first(): void
    {
        $migrator = $this->narrowFirst('FixtureCheapV2');

        // `JsonSchemaGenerator` never emits `narrowMarker`, so this can only be dispatch.
        $this->assertSame(NarrowFixtureGenerator::SCHEMA, $migrator->currentSchema(FixtureCheapV2::class));
    }

    public function test_a_class_the_narrow_generator_refuses_falls_through_rather_than_taking_generators_zero(): void
    {
        $migrator = $this->narrowFirst('FixtureCheapV2');

        $schema = $migrator->currentSchema(FixtureExpensiveV2::class);

        // Fell through to the ordinary generator: a real versioned `$id`, no marker.
        $this->assertArrayNotHasKey('narrowMarker', $schema['properties']);
        $this->assertStringContainsString('record-versioning-expensive', (string) $schema['$id']);
    }

    public function test_a_chain_that_refuses_the_record_type_yields_no_target_instead_of_throwing(): void
    {
        $migrator = new SchemaLadderMigrator($this->registry, new ChainedGenerator([new RefusingFixtureGenerator]));

        // `ChainedGenerator::generate()` would throw here; the guard turns that into the same "no
        // projectable target" answer the pinned-version branch already returns.
        $this->assertSame([], $migrator->currentSchema(FixtureCheapV2::class));
        $this->assertSame('', $migrator->currentId(FixtureCheapV2::class));
    }

    public function test_a_refused_record_type_is_left_at_its_stored_version_never_migrated_against_a_guess(): void
    {
        $reference = new SchemaLadderMigrator($this->registry, new JsonSchemaGenerator((array) config('data-schemas', [])));
        $v1Id = (string) SchemaId::from($reference->currentId(FixtureCheapV2::class))->withVersion(1);

        $migrator = new SchemaLadderMigrator($this->registry, new ChainedGenerator([new RefusingFixtureGenerator]));
        $original = ['title' => 'Aged', 'body' => 'Body'];

        $outcome = $migrator->reconcile($original, $v1Id, FixtureCheapV2::class);

        // The whole point of the guard. With no target, `$currentId` is null, `isOlder($stored, '')` is
        // not comparable, and the record classifies as `current` AT ITS STORED VERSION: nothing is
        // migrated and nothing is written back. NOT MIGRATING is recoverable the moment the host's
        // chain is corrected; migrating against a target the host never authorised is not.
        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_the_eager_drain_is_equally_refused_rather_than_draining_to_a_guessed_target(): void
    {
        $reference = new SchemaLadderMigrator($this->registry, new JsonSchemaGenerator((array) config('data-schemas', [])));
        $v1Id = (string) SchemaId::from($reference->currentId(FixtureCheapV2::class))->withVersion(1);

        $migrator = new SchemaLadderMigrator($this->registry, new ChainedGenerator([new RefusingFixtureGenerator]));

        $outcome = $migrator->reconcileEager(['title' => 'Aged'], $v1Id, FixtureCheapV2::class);

        // The drain is the path that WRITES, so the same refusal has to hold on it — otherwise the
        // read path is safe and the async worker quietly persists the guess.
        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame($v1Id, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }
}
