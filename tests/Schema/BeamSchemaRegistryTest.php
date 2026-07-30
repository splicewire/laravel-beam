<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The registry collapse (beam-particle-rename ticket 02): the ONE config-sources-driven
 * {@see BeamSchemaRegistry} at the {@see SchemaRegistry} contract seam. Proven against the SAME tier
 * implementations the host wires — the DB tier over `Beam::table('schemas')` (`beam_schemas`) and the
 * committed {@see FilesystemSchemaRegistry} — so the ordering/selection seam is exercised through the
 * real tiers, not fakes.
 *
 * Asserts: `['db','file']` READ ordering (db SHADOWS file); `['file']` boots + resolves with NO DB
 * tier (no `beam_schemas` table required — the unused factory is never invoked); `register()` lands in
 * the FIRST source; write-once immutability is preserved through the collapse; version enumeration
 * MERGES across sources.
 */
class BeamSchemaRegistryTest extends TestCase
{
    private const STEM = 'https://schemas.splicewire.app/test/registry-collapse';

    private string $frozenDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/bsr-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->frozenDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->frozenDir);

        parent::tearDown();
    }

    private function createDbTier(): void
    {
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name')->nullable()->index();
            $table->integer('version')->nullable();
            $table->string('fingerprint');
            $table->string('artifact'); // sqlite: json stored as text
            $table->timestamps();
        });
    }

    private function schema(string $id, string $marker): array
    {
        return ['$id' => $id, 'type' => 'object', 'x-marker' => $marker];
    }

    /** A `['db','file']` registry over both real tiers. */
    private function dbFileRegistry(): BeamSchemaRegistry
    {
        return new BeamSchemaRegistry(
            ['db', 'file'],
            [
                'db' => fn () => new DatabaseSchemaRegistry,
                'file' => fn () => new FilesystemSchemaRegistry($this->frozenDir),
            ],
        );
    }

    public function test_db_shadows_file_on_read_in_db_file_order(): void
    {
        $this->createDbTier();

        $id = self::STEM.'/1';

        // Same $id in BOTH tiers with DIFFERENT markers (the fingerprint differs, but each tier is a
        // separate write-once store, so this is legal cross-tier — the point is which one wins on read).
        (new FilesystemSchemaRegistry($this->frozenDir))->register($this->schema($id, 'from-file'));
        (new DatabaseSchemaRegistry)->register($this->schema($id, 'from-db'));

        $resolved = $this->dbFileRegistry()->get($id);

        $this->assertSame('from-db', $resolved['x-marker'], 'The DB tier (first source) must shadow the filesystem tier.');
        $this->assertTrue($this->dbFileRegistry()->has($id));
    }

    public function test_read_falls_through_to_file_when_db_lacks_the_id(): void
    {
        $this->createDbTier();

        $id = self::STEM.'/7';
        (new FilesystemSchemaRegistry($this->frozenDir))->register($this->schema($id, 'file-only'));

        $this->assertSame('file-only', $this->dbFileRegistry()->get($id)['x-marker']);
    }

    public function test_file_only_needs_no_db_tier(): void
    {
        // NOTE: createDbTier() is deliberately NOT called — no `beam_schemas` table exists.
        $registry = new BeamSchemaRegistry(
            ['file'],
            [
                'file' => fn () => new FilesystemSchemaRegistry($this->frozenDir),
                // A DB factory that would EXPLODE if ever invoked — proves the unused source is never built.
                'db' => fn () => throw new \RuntimeException('DB tier must never be constructed under [file].'),
            ],
        );

        $id = self::STEM.'/1';
        $registry->register($this->schema($id, 'file'));

        $this->assertSame('file', $registry->get($id)['x-marker']);
        $this->assertTrue($registry->has($id));
        $this->assertSame([$id], $registry->ids());
    }

    public function test_register_lands_in_the_first_source(): void
    {
        $this->createDbTier();

        $id = self::STEM.'/3';
        $this->dbFileRegistry()->register($this->schema($id, 'written'));

        // It landed in the DB tier (first source), NOT on disk.
        $this->assertTrue((new DatabaseSchemaRegistry)->has($id), 'register() must write to the first source (db).');
        $this->assertFalse((new FilesystemSchemaRegistry($this->frozenDir))->has($id), 'register() must NOT write to a later source (file).');
    }

    public function test_write_once_immutability_preserved(): void
    {
        $this->createDbTier();

        $id = self::STEM.'/4';
        $registry = $this->dbFileRegistry();

        $registry->register($this->schema($id, 'v1'));
        $registry->register($this->schema($id, 'v1')); // idempotent re-publish of identical shape — no throw

        $this->expectException(SchemaRegistryConflict::class);
        $registry->register($this->schema($id, 'CHANGED')); // differing fingerprint under same $id — rejected
    }

    public function test_version_enumeration_merges_across_sources(): void
    {
        $this->createDbTier();

        // v1 + v3 on disk, v2 + v3 in the DB — the DB v3 duplicates the file v3.
        (new FilesystemSchemaRegistry($this->frozenDir))->register($this->schema(self::STEM.'/1', 'f1'));
        (new FilesystemSchemaRegistry($this->frozenDir))->register($this->schema(self::STEM.'/3', 'f3'));
        (new DatabaseSchemaRegistry)->register($this->schema(self::STEM.'/2', 'd2'));
        (new DatabaseSchemaRegistry)->register($this->schema(self::STEM.'/3', 'd3'));

        $this->assertSame([1, 2, 3], $this->dbFileRegistry()->versionsFor(self::STEM), 'Versions must merge + de-dupe across both tiers, ascending.');
    }

    public function test_empty_sources_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BeamSchemaRegistry([], ['db' => fn () => new DatabaseSchemaRegistry]);
    }

    public function test_the_collapsed_registry_is_the_contract_and_the_enumerator(): void
    {
        $registry = new BeamSchemaRegistry(['file'], ['file' => fn () => new FilesystemSchemaRegistry($this->frozenDir)]);

        $this->assertInstanceOf(SchemaRegistry::class, $registry);
        $this->assertInstanceOf(EnumeratesVersions::class, $registry);
    }
}
