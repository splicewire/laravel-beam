<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit;

/**
 * particle-doctrine-followups #14 — the schema leg's first drift guard. The pure `check()` core is
 * exercised with pre-built rows (no filesystem, no container), mirroring the sibling audits'
 * injected-content discipline. The distinction under test: "declared but never emitted" and "declared
 * but stale" ARE findings; "nothing declared" is the negative-space detector's territory and here
 * reads as a pass.
 */
class SchemaProjectionDriftAuditTest extends TestCase
{
    private const GENERATED = ['type' => 'object', 'title' => 'WidgetData', 'properties' => ['id' => ['type' => 'string']]];

    /** @param list<array<string, mixed>> $rows */
    private function audit(array $rows): SchemaProjectionDriftAudit
    {
        return new SchemaProjectionDriftAudit($rows);
    }

    private function row(string $class, ?string $disk, ?array $generated = self::GENERATED): array
    {
        return [
            'class' => $class,
            'declaredBy' => "resource 'widgets' data:",
            'path' => "/host/resources/schemas/App/Data/{$class}.schema.json",
            'disk' => $disk,
            'generated' => $generated,
        ];
    }

    public function test_a_declared_class_with_no_disk_file_is_declared_but_never_emitted(): void
    {
        $findings = $this->audit([$this->row('App\\Data\\WidgetData', disk: null)])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('declared but never emitted', $findings[0]->detail);
        $this->assertStringContainsString("resource 'widgets' data:", $findings[0]->detail);
        $this->assertStringContainsString('schemas:generate', $findings[0]->detail);
    }

    public function test_a_disk_file_that_no_longer_matches_the_declaration_is_stale(): void
    {
        $stale = json_encode(['type' => 'object', 'title' => 'WidgetData', 'properties' => []]);

        $findings = $this->audit([$this->row('App\\Data\\WidgetData', disk: $stale)])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('STALE relative to the declaration', $findings[0]->detail);
    }

    public function test_a_fresh_projection_passes_regardless_of_json_formatting(): void
    {
        // Byte formatting is the writer's concern; equality is structural.
        $pretty = json_encode(self::GENERATED, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $findings = $this->audit([$this->row('App\\Data\\WidgetData', disk: $pretty)])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('fresh disk schema projections', $findings[0]->detail);
    }

    public function test_nothing_declared_in_scope_is_a_pass_not_a_finding(): void
    {
        // "No schema because nothing was declared" belongs to the negative-space detector.
        $findings = $this->audit([])->run();

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
        $findings = $this->audit([
            $this->row('App\\Data\\ZebraData', disk: null),
            $this->row('App\\Data\\AlphaData', disk: null),
        ])->run();

        $this->assertStringContainsString('AlphaData', $findings[0]->detail);
        $this->assertStringContainsString('ZebraData', $findings[1]->detail);
    }

    public function test_a_class_the_generator_could_not_process_is_skipped_not_fabricated(): void
    {
        // Generator threw for this class — the round-trip audit's territory, not projection drift.
        $findings = $this->audit([$this->row('App\\Data\\BrokenData', disk: null, generated: null)])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }
}
