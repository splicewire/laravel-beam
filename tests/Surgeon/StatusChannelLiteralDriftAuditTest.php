<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\StatusChannelLiteralDriftAudit;

/**
 * Ticket 35 (surgeon-audit-viability) — catches an FE Echo channel literal reconstructing beam-workflows'
 * dotted-FQCN Seam A convention instead of reading the server-resolved `statusChannel` DTO field.
 * `check()` is the pure core: a plain list of already-extracted `{file, line, dottedPath}` rows in, no
 * filesystem walk.
 */
class StatusChannelLiteralDriftAuditTest extends TestCase
{
    private function audit(): StatusChannelLiteralDriftAudit
    {
        return new StatusChannelLiteralDriftAudit([]);
    }

    public function test_a_literal_naming_a_real_class_produces_no_finding(): void
    {
        $findings = $this->audit()->check([
            ['file' => 'ui/src/features/x.ts', 'line' => 12, 'dottedPath' => str_replace('\\', '.', self::class)],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_a_literal_naming_a_relocated_or_nonexistent_class_produces_a_fail_finding(): void
    {
        $findings = $this->audit()->check([
            ['file' => 'ui/src/features/studio/CompositionEditorPage.tsx', 'line' => 643, 'dottedPath' => 'App.Models.Composition'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('App\\Models\\Composition', $findings[0]->detail);
        $this->assertStringContainsString('CompositionEditorPage.tsx:643', $findings[0]->detail);
    }

    public function test_multiple_literals_each_produce_their_own_finding(): void
    {
        $findings = $this->audit()->check([
            ['file' => 'a.ts', 'line' => 1, 'dottedPath' => 'App.Models.Composition'],
            ['file' => 'b.ts', 'line' => 2, 'dottedPath' => 'App.Models.Tenant'],
        ]);

        $this->assertCount(2, $findings);
    }

    public function test_the_scan_pattern_extracts_the_dotted_path_up_to_the_interpolation(): void
    {
        $pattern = (new \ReflectionClass(StatusChannelLiteralDriftAudit::class))->getConstant('PATTERN');

        $this->assertSame(
            1,
            preg_match($pattern, '            channel={`status.App.Models.Composition.${id}`}', $matches),
        );
        $this->assertSame('App.Models.Composition.', $matches[1]);
    }

    public function test_the_scan_pattern_does_not_match_a_plain_string_with_no_interpolation(): void
    {
        $pattern = (new \ReflectionClass(StatusChannelLiteralDriftAudit::class))->getConstant('PATTERN');

        $this->assertSame(0, preg_match($pattern, '            channel="workflow-subject.post.1"'));
    }
}
