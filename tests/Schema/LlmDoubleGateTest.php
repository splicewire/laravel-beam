<?php

namespace Splicewire\Beam\Tests\Schema;

use ReflectionClass;
use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Schema\SchemaLadderMigrator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureLlmMigrator;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureLlmV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureLlmV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureUnmigratableV2;
use Splicewire\Beam\Tests\TestCase;

/**
 * The armed LLM path, proven THROUGH the port (the `reconcileEager` entry), never by
 * calling the rung directly. The deterministic rungs cannot rename v1 `name` → v2
 * `fullName` (minLength 3, no #[WasNamed]), so the upgrade is reachable only by the
 * LLM-try rung — and the safety envelope is unchanged from today:
 *
 *  - the rung is DOUBLY gated — the host decision (kill-switch + tenant entitlement,
 *    distilled to the `allowLlm` argument by the consumer's gate) AND the schema's
 *    `x-migrate: llm` opt-in must BOTH hold before the model migrator runs;
 *  - missing EITHER gate → the migrator is never invoked;
 *  - a non-conforming model candidate is rejected by the acceptance gate and
 *    quarantined as `failed`, the original preserved.
 *
 * The kill-switch + entitlement composition itself lives in the consumer's gate
 * (Composition's `LlmMigrationGate`, exercised by its own unchanged parity test); at
 * the port boundary it is the `allowLlm` decision.
 */
class LlmDoubleGateTest extends TestCase
{
    /**
     * This test app simulates a host that SERVES its schemas: its fixtures are `SchemaIdentity`
     * classes, and the `$id` literals below are minted under this authority (ticket 85).
     */
    protected function schemaAuthority(): string|bool|null
    {
        return self::SCHEMA_AUTHORITY;
    }

    private string $frozenDir;

    private JsonSchemaGenerator $generator;

    private FilesystemSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/rv-llm-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $this->generator = new JsonSchemaGenerator(config('data-schemas', []));
        $this->registry = new FilesystemSchemaRegistry($this->frozenDir);

        // Freeze the old artifacts the drain diffs against.
        foreach ([FixtureLlmV1::class, FixtureUnmigratableV1::class] as $cls) {
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

    /** Build the migrator with an injected factory yielding the given model migrator. */
    private function llmMigrator(FixtureLlmMigrator $llm): SchemaLadderMigrator
    {
        return new SchemaLadderMigrator(
            $this->registry,
            $this->generator,
            transforms: new TransformRegistry,
            llmMigratorFactory: fn (array $old, array $new, string $recordType): FixtureLlmMigrator => $llm,
        );
    }

    public function test_migrates_via_the_llm_rung_when_armed_and_the_schema_opts_in_and_the_candidate_validates(): void
    {
        $migrator = $this->llmMigrator(new FixtureLlmMigrator(['fullName' => 'Ada Lovelace']));
        $v1Id = (string) SchemaId::from($migrator->currentId(FixtureLlmV2::class))->withVersion(1);

        $outcome = $migrator->reconcileEager(['name' => 'Ada Lovelace'], $v1Id, FixtureLlmV2::class, allowLlm: true);

        $this->assertSame(MigrationStatus::Current, $outcome->status);
        $this->assertSame(['fullName' => 'Ada Lovelace'], $outcome->payload);
        $this->assertTrue($outcome->shouldWriteBack);
    }

    public function test_never_invokes_the_model_when_not_armed_host_gate_closed_and_quarantines(): void
    {
        // A migrator that throws if reached — proving allowLlm:false closes the gate
        // before the rung is even appended.
        $migrator = $this->llmMigrator(new FixtureLlmMigrator(failIfCalled: true));
        $v1Id = (string) SchemaId::from($migrator->currentId(FixtureLlmV2::class))->withVersion(1);

        $outcome = $migrator->reconcileEager(['name' => 'Ada Lovelace'], $v1Id, FixtureLlmV2::class, allowLlm: false);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame(['name' => 'Ada Lovelace'], $outcome->payload);
    }

    public function test_never_invokes_the_model_when_the_schema_does_not_opt_in_even_if_armed(): void
    {
        // FixtureUnmigratableV2 pins a custom transform, NOT llm — so the LLM rung
        // self-gates OFF (no x-migrate:llm opt-in) and abstains, even armed. The throwing
        // migrator proves it is never reached.
        $migrator = $this->llmMigrator(new FixtureLlmMigrator(failIfCalled: true));
        $v1Id = (string) SchemaId::from($migrator->currentId(FixtureUnmigratableV2::class))->withVersion(1);

        $outcome = $migrator->reconcileEager(['title' => 'Only Title'], $v1Id, FixtureUnmigratableV2::class, allowLlm: true);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame(['title' => 'Only Title'], $outcome->payload);
    }

    public function test_quarantines_a_non_conforming_model_candidate_via_the_acceptance_gate_original_preserved(): void
    {
        // The model returns a candidate that does NOT conform (no fullName) — the
        // acceptance gate rejects it, the rung abstains, and the null floor quarantines.
        $migrator = $this->llmMigrator(new FixtureLlmMigrator(['wrong' => 'x']));
        $v1Id = (string) SchemaId::from($migrator->currentId(FixtureLlmV2::class))->withVersion(1);

        $outcome = $migrator->reconcileEager(['name' => 'Ada Lovelace'], $v1Id, FixtureLlmV2::class, allowLlm: true);

        $this->assertSame(MigrationStatus::Failed, $outcome->status);
        $this->assertSame(['name' => 'Ada Lovelace'], $outcome->payload);
    }
}
