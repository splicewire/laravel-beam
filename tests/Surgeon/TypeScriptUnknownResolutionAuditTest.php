<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\TypeScriptUnknownResolutionAudit;

/**
 * Ticket 37 (surgeon-audit-viability) — catches a `#[TypeScript]`-attributed class whose emitted TS
 * property degraded to bare `unknown`/`unknown[]`, the signature of an unresolvable docblock `@var`
 * class reference (traced live for `WorkflowCatalogData.guards`/`effects`/`principals`). `check()` is
 * the pure core: a plain `generated.d.ts` string in, no filesystem.
 */
class TypeScriptUnknownResolutionAuditTest extends TestCase
{
    private function audit(): TypeScriptUnknownResolutionAudit
    {
        return new TypeScriptUnknownResolutionAudit(null);
    }

    public function test_a_fully_resolved_class_produces_no_finding(): void
    {
        $dts = <<<'TS'
        export type WorkflowCatalogData = {
        blueprintSchema: Record<string, any>,
        guards: Splicewire.Tower.Data.GuardCatalogEntryData[],
        types: Splicewire.Beam.Workflows.Data.WorkflowTypeOptionData[],
        canAuthor: boolean,
        };
        TS;

        $this->assertSame([], $this->audit()->check($dts));
    }

    public function test_the_pre_fix_workflow_catalog_data_reproduction_produces_three_findings(): void
    {
        $dts = <<<'TS'
        export type WorkflowCatalogData = {
        blueprintSchema: Record<string, any>,
        guards: unknown[],
        effects: unknown[],
        types: Splicewire.Beam.Workflows.Data.WorkflowTypeOptionData[],
        principals: unknown[],
        canAuthor: boolean,
        };
        TS;

        $findings = $this->audit()->check($dts);

        $this->assertCount(3, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Warn, $finding->status);
        }
        $this->assertStringContainsString('WorkflowCatalogData.guards', $findings[0]->detail);
        $this->assertStringContainsString('WorkflowCatalogData.effects', $findings[1]->detail);
        $this->assertStringContainsString('WorkflowCatalogData.principals', $findings[2]->detail);
    }

    public function test_a_bare_unknown_scalar_property_is_also_flagged(): void
    {
        $dts = <<<'TS'
        export type SomeData = {
        weird: unknown,
        };
        TS;

        $findings = $this->audit()->check($dts);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('SomeData.weird', $findings[0]->detail);
    }

    public function test_a_property_outside_any_class_block_is_ignored(): void
    {
        $findings = $this->audit()->check("guards: unknown[],\n");

        $this->assertSame([], $findings);
    }

    public function test_multiple_classes_each_scope_their_own_findings(): void
    {
        $dts = <<<'TS'
        export type FirstData = {
        a: unknown[],
        };
        export type SecondData = {
        b: string,
        };
        export type ThirdData = {
        c: unknown,
        };
        TS;

        $findings = $this->audit()->check($dts);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('FirstData.a', $findings[0]->detail);
        $this->assertStringContainsString('ThirdData.c', $findings[1]->detail);
    }

    /**
     * It used to assert `[]` here. Degrading must not CRASH, but it must not go silent either: with no
     * readable declaration file nothing was scanned, and reporting nothing made that indistinguishable
     * from a clean scan. api-surface-coherence 128 gave it a line — Pass, flagged inconclusive.
     */
    public function test_null_content_degrades_to_an_inconclusive_finding_rather_than_erroring(): void
    {
        $findings = $this->audit()->check(null);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('nothing was scanned', $findings[0]->detail);
    }
}
