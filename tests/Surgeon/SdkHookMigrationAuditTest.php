<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Surgeon\SdkHookMigrationAudit;
use Splicewire\Beam\Surgeon\SdkHookMigrationBridge;

/**
 * The schema-registry case (client-sdk-codegen #05): `useSchemaRegistryIndex` was hand-written in
 * `schema-registry/api.ts`, then a generated hook of the same name started covering
 * `schema_registry.index`. Pure unit test over an in-memory Node-scan report — no bridge, no disk, no
 * Node — mirroring {@see SdkEndpointDriftAuditTest}.
 */
class SdkHookMigrationAuditTest extends TestCase
{
    private function audit(): SdkHookMigrationAudit
    {
        return new SdkHookMigrationAudit(new SdkHookMigrationBridge, '/nonexistent', '/nonexistent', '/nonexistent');
    }

    public function test_a_candidate_produces_one_fixable_finding_per_edit(): void
    {
        $report = [
            'candidates' => [[
                'functionName' => 'useSchemaRegistryIndex',
                'routeName' => 'schema_registry.index',
                'apiFile' => '/ui/src/features/schema-registry/api.ts',
                'hookModule' => 'schema_registry',
                'edits' => [
                    ['file' => '/ui/src/features/schema-registry/api.ts', 'old' => 'export function useSchemaRegistryIndex() { ... }', 'new' => ''],
                    ['file' => '/ui/src/features/schema-registry/SchemaRegistryPage.tsx', 'old' => "import { useSchemaRegistryIndex } from './api';", 'new' => "import { useSchemaRegistryIndex } from '@/generated/hooks/schema_registry';"],
                ],
            ]],
            'exceptions' => [],
        ];

        $findings = $this->audit()->suggestFor($report);

        $this->assertCount(2, $findings);

        /** @var FixableFinding $wrapperFinding */
        $wrapperFinding = $findings[0];
        $this->assertTrue($wrapperFinding->isFixable());
        $this->assertSame('fail', $wrapperFinding->finding->status->value);
        $this->assertSame('sdk.hook-migration', $wrapperFinding->finding->check);
        $this->assertSame('literal-rewrite', $wrapperFinding->suggestion->kind);
        $this->assertSame('/ui/src/features/schema-registry/api.ts', $wrapperFinding->suggestion->payload['file']);
        $this->assertSame('', $wrapperFinding->suggestion->payload['new']);

        /** @var FixableFinding $importFinding */
        $importFinding = $findings[1];
        $this->assertTrue($importFinding->isFixable());
        $this->assertSame("import { useSchemaRegistryIndex } from './api';", $importFinding->suggestion->payload['old']);
        $this->assertSame("import { useSchemaRegistryIndex } from '@/generated/hooks/schema_registry';", $importFinding->suggestion->payload['new']);
    }

    public function test_an_exception_is_advisory_not_fixable(): void
    {
        $report = [
            'candidates' => [],
            'exceptions' => [[
                'functionName' => 'useFilteredThings',
                'routeName' => 'thing.index',
                'apiFile' => '/ui/src/features/things/api.ts',
                'reason' => 'queryFn passes extra request config beyond route(...) — possible query-string filtering, needs manual review',
            ]],
        ];

        $findings = $this->audit()->suggestFor($report);

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]->suggestion);
        $this->assertFalse($findings[0]->isFixable());
        $this->assertSame('warn', $findings[0]->finding->status->value);
        $this->assertStringContainsString('query-string filtering', $findings[0]->finding->detail);
    }

    public function test_an_empty_report_produces_no_findings(): void
    {
        $this->assertSame([], $this->audit()->suggestFor(['candidates' => [], 'exceptions' => []]));
    }

    /**
     * It used to assert `[]` here — the bridge is the audit's only instrument, so with it absent the
     * sweep printed NO line at all and a mis-wired bridge read as "nothing to report" rather than as
     * "not looked at". api-surface-coherence 128 gave it a line to print: a Pass, so no build reddens,
     * flagged inconclusive so a reader can tell the two apart.
     */
    public function test_suggest_operations_reports_an_inconclusive_finding_when_the_bridge_is_unavailable(): void
    {
        $audit = new SdkHookMigrationAudit(new SdkHookMigrationBridge(script: null), '/x', '/y', '/z');

        // Both channels: suggestOperations() pairs the finding with a (null) operation; run() unwraps it.
        foreach ([$audit->suggestOperations()[0]->finding, $audit->run()[0]] as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status);
            $this->assertFalse($finding->conclusive);
            $this->assertStringContainsString('not available', $finding->detail);
        }

        $this->assertCount(1, $audit->suggestOperations());
        $this->assertCount(1, $audit->run());
    }
}
