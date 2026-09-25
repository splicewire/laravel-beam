<?php

namespace Splicewire\Beam\Tests\Schema;

use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\TestCase;

/**
 * The forward twin of {@see ReadAtVersionEmptyObjectTest}: migrate-on-read (v1 → v2) spells an empty
 * stored object as `{}` before the ladder's acceptance gate sees it.
 *
 * A stored payload is array-decoded, so `{"config": {}}` reads back as `['config' => []]`. Every rung
 * of the default ladder carries that `[]` into its candidate unchanged, and the gate refuses it against
 * the target's `type: object` — so a record whose only defect is PHP's spelling of an empty object fell
 * through every rung to the quarantine floor and read `failed` (splicewire-app suite-green TRIAGE,
 * "latent sibling of decision 5").
 *
 * The outcome keeps the caller's spelling: the migrated payload carries `[]` where the stored payload
 * did, and a failed outcome preserves the ORIGINAL payload, not the re-spelled document.
 */
class ForwardReconcileEmptyObjectTest extends TestCase
{
    private const V1 = 'https://schemas.test/fixture/threadish/1';

    private const V2 = 'https://schemas.test/fixture/threadish/2';

    private string $frozenDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/fwd-empty-object-'.getmypid().'-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->frozenDir.'/*') ?: []);
        @rmdir($this->frozenDir);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function v1(): array
    {
        return [
            '$id' => self::V1,
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

    /**
     * v2 = v1 plus one added field with a declared default: a pure structural step.
     *
     * @return array<string, mixed>
     */
    private static function v2(): array
    {
        $v2 = self::v1();
        $v2['$id'] = self::V2;
        $v2['properties']['label'] = ['type' => 'string', 'default' => 'untitled'];
        $v2['required'][] = 'label';

        return $v2;
    }

    private function migrator(): SchemaLadderMigrator
    {
        $registry = new FilesystemSchemaRegistry($this->frozenDir);
        $registry->register(self::v1());

        $resolver = new class(self::v2()) implements SchemaTargetResolver
        {
            /** @param array<string, mixed> $current */
            public function __construct(private array $current) {}

            public function targetFor(string $recordType, ?int $version = null): array
            {
                return $this->current;
            }
        };

        return new SchemaLadderMigrator(
            $registry,
            new JsonSchemaGenerator(config('data-schemas', [])),
            targetResolver: $resolver,
        );
    }

    public function test_an_empty_stored_object_migrates_forward_on_the_structural_rung(): void
    {
        $stored = ['mode' => 'chat', 'config' => [], 'tags' => [], 'meta' => []];

        $outcome = $this->migrator()->reconcile($stored, self::V1, 'fixture/threadish');

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertTrue($outcome->shouldWriteBack);
        $this->assertSame(self::V2, $outcome->versionId);
        // The caller's spelling survives: `[]` in, `[]` out — never a stdClass the record's readers
        // did not put there.
        $this->assertSame(
            ['mode' => 'chat', 'config' => [], 'tags' => [], 'meta' => [], 'label' => 'untitled'],
            $outcome->payload,
        );
    }

    public function test_the_eager_drain_takes_the_same_spelling(): void
    {
        $stored = ['mode' => 'chat', 'config' => [], 'tags' => [], 'meta' => ['k' => 'v']];

        $outcome = $this->migrator()->reconcileEager($stored, self::V1, 'fixture/threadish');

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertTrue($outcome->shouldWriteBack);
        $this->assertSame([], $outcome->payload['config']);
        $this->assertSame(['k' => 'v'], $outcome->payload['meta']);
    }

    public function test_a_non_empty_list_where_an_object_belongs_still_fails_with_the_original_preserved(): void
    {
        // Only the EMPTY value is ambiguous; a list with members is an array in any spelling.
        $stored = ['mode' => 'chat', 'config' => ['a', 'b'], 'tags' => [], 'meta' => []];

        $outcome = $this->migrator()->reconcile($stored, self::V1, 'fixture/threadish');

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame($stored, $outcome->payload);
        $this->assertSame(self::V1, $outcome->versionId);
    }
}
