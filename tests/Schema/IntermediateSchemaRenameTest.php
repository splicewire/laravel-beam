<?php

namespace Splicewire\Beam\Tests\Schema;

use LogicException;
use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureLlmMigrator;
use Splicewire\Beam\Tests\TestCase;

class IntermediateSchemaRenameTest extends TestCase
{
    private const STEM = 'https://schemas.test/ticket31/article';

    private string $directory;

    private FilesystemSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ticket31-schema-chain-'.getmypid().'-'.uniqid();
        $this->registry = new FilesystemSchemaRegistry($this->directory);
        foreach ([self::v1(), self::v2(), self::v3(), self::v4()] as $schema) {
            $this->registry->register($schema);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_a_rename_declared_only_on_intermediate_v2_survives_a_v1_to_v3_migration(): void
    {
        $outcome = $this->migrator()->migrate(['summary' => 'VALUE-KEEPS-ME-12345'], self::id(1), self::id(3));

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame(['blurb' => 'VALUE-KEEPS-ME-12345'], $outcome->payload);
        $this->assertSame(self::id(3), $outcome->versionId);
    }

    public function test_a_multi_hop_chain_applies_each_intermediate_rename_in_order(): void
    {
        $outcome = $this->migrator()->migrate(['summary' => 'VALUE-KEEPS-ME-12345'], self::id(1), self::id(4));

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame(['headline' => 'VALUE-KEEPS-ME-12345'], $outcome->payload);
        $this->assertSame(self::id(4), $outcome->versionId);
    }

    public function test_versioned_migration_refuses_a_registry_that_cannot_enumerate_intermediate_versions(): void
    {
        $registry = new class($this->registry) implements SchemaRegistry
        {
            public function __construct(private FilesystemSchemaRegistry $inner) {}

            public function register(array $schema): void
            {
                $this->inner->register($schema);
            }

            public function get(string $id): ?array
            {
                return $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                return $this->inner->has($id);
            }

            public function ids(): array
            {
                return $this->inner->ids();
            }
        };

        $migrator = new SchemaLadderMigrator($registry, new JsonSchemaGenerator(config('data-schemas', [])));

        try {
            $migrator->migrate(['summary' => 'VALUE-KEEPS-ME-12345'], self::id(1), self::id(3));
            $this->fail('Expected an explicit refusal when intermediate versions cannot be enumerated.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must implement', $exception->getMessage());
            $this->assertStringContainsString('EnumeratesVersions', $exception->getMessage());
        }
    }

    public function test_the_eager_host_factory_receives_each_hops_source_and_target(): void
    {
        $pairs = [];
        $migrator = new SchemaLadderMigrator(
            $this->registry,
            new JsonSchemaGenerator(config('data-schemas', [])),
            targetResolver: new class(self::v4()) implements SchemaTargetResolver
            {
                /** @param array<string, mixed> $target */
                public function __construct(private array $target) {}

                public function targetFor(string $recordType, ?int $version = null): array
                {
                    return $this->target;
                }
            },
            llmMigratorFactory: function (array $from, array $to, string $recordType) use (&$pairs): FixtureLlmMigrator {
                $pairs[] = [$from['$id'], $to['$id']];

                return new FixtureLlmMigrator(failIfCalled: true);
            },
        );

        $outcome = $migrator->reconcileEager(
            ['summary' => 'VALUE-KEEPS-ME-12345'],
            self::id(1),
            'fixture/article',
            allowLlm: true,
        );

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame(['headline' => 'VALUE-KEEPS-ME-12345'], $outcome->payload);
        $this->assertSame([
            [self::id(1), self::id(2)],
            [self::id(2), self::id(3)],
            [self::id(3), self::id(4)],
        ], $pairs);
    }

    private function migrator(): SchemaLadderMigrator
    {
        return new SchemaLadderMigrator($this->registry, new JsonSchemaGenerator(config('data-schemas', [])));
    }

    private static function id(int $version): string
    {
        return self::STEM.'/'.$version;
    }

    /** @return array<string, mixed> */
    private static function v1(): array
    {
        return ['$id' => self::id(1), 'type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']];
    }

    /** @return array<string, mixed> */
    private static function v2(): array
    {
        return ['$id' => self::id(2), 'type' => 'object', 'properties' => ['blurb' => ['type' => 'string', 'x-migrate-from' => 'summary']], 'required' => ['blurb']];
    }

    /** @return array<string, mixed> */
    private static function v3(): array
    {
        return ['$id' => self::id(3), 'type' => 'object', 'properties' => ['blurb' => ['type' => 'string']], 'required' => ['blurb']];
    }

    /** @return array<string, mixed> */
    private static function v4(): array
    {
        return ['$id' => self::id(4), 'type' => 'object', 'properties' => ['headline' => ['type' => 'string', 'x-migrate-from' => 'blurb']], 'required' => ['headline']];
    }
}
