<?php

namespace Splicewire\Beam\Tests\Schema;

use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\TestCase;

/**
 * A pinned-version read spells an empty stored object as `{}` before the schema check.
 *
 * A stored payload is array-decoded, so `{"config": {}}` reads back as `['config' => []]`, and opis
 * refuses `[]` against `type: object`. The flagship's `ThreadParticleTest` "embed_session
 * frozen-config" read a thread with an empty `config` pinned at v1 and got `failed` for that reason
 * alone. `readAtVersion()` now restores `{}` where the pinned schema says object and not array — the
 * same walk the intake door runs.
 *
 * The gate itself stays strict: `[]` is still not an object to {@see AcceptanceGate}, which
 * data-schemas `DeclaredDefaultKeywordTest` pins. The fix is in how the migrator spells the stored
 * document, not in what the gate accepts.
 */
class ReadAtVersionEmptyObjectTest extends TestCase
{
    private const V1 = 'https://schemas.test/fixture/threadish/1';

    private string $frozenDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/rv-empty-object-'.getmypid().'-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->frozenDir.'/*') ?: []);
        @rmdir($this->frozenDir);

        parent::tearDown();
    }

    private function migrator(): SchemaLadderMigrator
    {
        $resolver = new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return [
                    '$id' => 'https://schemas.test/fixture/threadish/1',
                    'type' => 'object',
                    'properties' => [
                        'mode' => ['type' => 'string'],
                        'config' => ['type' => 'object'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'meta' => ['$ref' => '#/$defs/Meta'],
                    ],
                    'required' => ['mode', 'config', 'tags', 'meta'],
                    '$defs' => ['Meta' => ['type' => 'object']],
                ];
            }
        };

        return new SchemaLadderMigrator(
            new FilesystemSchemaRegistry($this->frozenDir),
            new JsonSchemaGenerator(config('data-schemas', [])),
            targetResolver: $resolver,
        );
    }

    public function test_an_empty_stored_object_conforms_under_the_pinned_version(): void
    {
        $stored = ['mode' => 'chat', 'config' => [], 'tags' => [], 'meta' => []];

        $outcome = $this->migrator()->readAtVersion($stored, 'fixture/threadish', 1);

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame(self::V1, $outcome->versionId);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_the_gate_itself_still_refuses_an_array_for_an_object(): void
    {
        $target = $this->migrator()->targetSchema('fixture/threadish', 1);

        $this->assertFalse((new AcceptanceGate)->accepts(
            ['mode' => 'chat', 'config' => [], 'tags' => [], 'meta' => (object) []],
            $target,
        ));
    }

    public function test_a_non_empty_list_where_an_object_belongs_still_fails(): void
    {
        // Only the EMPTY value is ambiguous. A list with members is an array in any spelling.
        $stored = ['mode' => 'chat', 'config' => ['a', 'b'], 'tags' => [], 'meta' => []];

        $outcome = $this->migrator()->readAtVersion($stored, 'fixture/threadish', 1);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($stored, $outcome->payload);
        $this->assertFalse($outcome->shouldWriteBack);
    }

    public function test_an_empty_value_where_the_schema_says_array_stays_an_array(): void
    {
        // The mirror defect: `tags: {}` would fail `type: array`. The walk restores objects only where
        // the schema says object and not array, so `tags: []` still conforms as the list it is.
        $outcome = $this->migrator()->readAtVersion(
            ['mode' => 'chat', 'config' => ['k' => 'v'], 'tags' => [], 'meta' => ['x' => 1]],
            'fixture/threadish',
            1,
        );

        $this->assertSame(MigrationStatus::Current, $outcome->status);
    }
}
