<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Support\FileSchemaCollection;
use Schemastud\DataSchemas\Support\WrittenSchema;
use Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit;

/**
 * particle-doctrine-followups #14 — the schema leg's first drift guard. The pure `check()` core is
 * exercised with a pre-built {@see FileSchemaCollection} and pre-read documents (no filesystem, no
 * container), mirroring the sibling audits' injected-content discipline. The distinction under test:
 * "declared but never emitted" and "declared but stale" ARE findings; "nothing declared" is the
 * negative-space detector's territory and here reads as a pass.
 *
 * The generated/on-disk halves are separate arguments now rather than two columns of one row,
 * because that is the shape data-schemas' own `SchemaCollection::diffAgainst()` takes — the audit
 * no longer carries a private copy of the compare.
 */
class SchemaProjectionDriftAuditTest extends TestCase
{
    private const GENERATED = ['type' => 'object', 'title' => 'WidgetData', 'properties' => ['id' => ['type' => 'string']]];

    /**
     * @param  array<string, ?array<string, mixed>>  $classes  class => the document on disk, or null for "no file"
     */
    private function findings(array $classes): array
    {
        $schemas = new FileSchemaCollection;
        $onDisk = [];
        $declaredBy = [];

        foreach ($classes as $class => $disk) {
            $schemas->push(new WrittenSchema(
                className: $class,
                schema: self::GENERATED,
                outputPath: "/host/resources/schemas/{$class}.schema.json",
            ));

            $declaredBy[$class] = "resource 'widgets' data:";

            if ($disk !== null) {
                $onDisk[$class] = $disk;
            }
        }

        return (new SchemaProjectionDriftAudit($schemas, null, $onDisk, $declaredBy))->run();
    }

    public function test_a_declared_class_with_no_disk_file_is_declared_but_never_emitted(): void
    {
        $findings = $this->findings(['App\\Data\\WidgetData' => null]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('declared but never emitted', $findings[0]->detail);
        $this->assertStringContainsString("resource 'widgets' data:", $findings[0]->detail);
        $this->assertStringContainsString('schemas:generate', $findings[0]->detail);
    }

    public function test_a_disk_file_that_no_longer_matches_the_declaration_is_stale(): void
    {
        $findings = $this->findings([
            'App\\Data\\WidgetData' => ['type' => 'object', 'title' => 'WidgetData', 'properties' => []],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('STALE relative to the declaration', $findings[0]->detail);
    }

    public function test_a_fresh_projection_passes_regardless_of_key_order(): void
    {
        // Byte formatting is the writer's concern; equality is structural (SchemaFingerprint).
        $findings = $this->findings([
            'App\\Data\\WidgetData' => ['properties' => ['id' => ['type' => 'string']], 'title' => 'WidgetData', 'type' => 'object'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('fresh disk schema projections', $findings[0]->detail);
    }

    public function test_reworded_prose_is_not_drift(): void
    {
        // `title`/`description`/`examples` are non-structural — SchemaFingerprint::VOLATILE_KEYS.
        // Regenerating would change nothing anyone consumes, so reporting it is a false backlog item.
        $findings = $this->findings([
            'App\\Data\\WidgetData' => ['type' => 'object', 'title' => 'A widget, restated', 'properties' => ['id' => ['type' => 'string']]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_nothing_declared_in_scope_is_a_pass_not_a_finding(): void
    {
        // "No schema because nothing was declared" belongs to the negative-space detector.
        $findings = $this->findings([]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('nothing to project', $findings[0]->detail);
    }

    public function test_unavailable_generator_is_a_stated_skip(): void
    {
        $findings = (new SchemaProjectionDriftAudit(null, 'data-schemas not installed'))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('skipped', $findings[0]->detail);
    }

    public function test_findings_sort_by_class_name_for_byte_stable_output(): void
    {
        $findings = $this->findings([
            'App\\Data\\ZebraData' => null,
            'App\\Data\\AlphaData' => null,
        ]);

        $this->assertStringContainsString('AlphaData', $findings[0]->detail);
        $this->assertStringContainsString('ZebraData', $findings[1]->detail);
    }

    public function test_the_two_finding_kinds_interleave_by_class_name_rather_than_clustering(): void
    {
        // diffAgainst() returns `missing` and `drifted` as separate lists; emitting them in that
        // order would make output depend on kind, not on name.
        $findings = $this->findings([
            'App\\Data\\ZebraData' => null,
            'App\\Data\\AlphaData' => ['type' => 'object', 'properties' => []],
        ]);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('AlphaData', $findings[0]->detail);
        $this->assertStringContainsString('STALE', $findings[0]->detail);
        $this->assertStringContainsString('ZebraData', $findings[1]->detail);
        $this->assertStringContainsString('never emitted', $findings[1]->detail);
    }
}
