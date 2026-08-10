<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\AgentsMdConventionAudit;
use Splicewire\Beam\Tests\TestCase;

class AgentsMdConventionAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(base_path('CLAUDE.md'));
        File::delete(base_path('AGENTS.md'));

        parent::tearDown();
    }

    public function test_warns_when_claude_md_is_still_tracked(): void
    {
        File::put(base_path('CLAUDE.md'), 'stuff');
        $this->fakeTracked(['CLAUDE.md']);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'AGENTS.md committed / CLAUDE.md gitignored');

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('git-tracked', $finding->detail);
    }

    public function test_warns_when_agents_md_is_missing(): void
    {
        $this->fakeTracked([]);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'AGENTS.md committed / CLAUDE.md gitignored');

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('No AGENTS.md', $finding->detail);
    }

    public function test_warns_when_agents_md_exists_but_is_not_committed(): void
    {
        File::put(base_path('AGENTS.md'), 'stuff');
        $this->fakeTracked([]);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'AGENTS.md committed / CLAUDE.md gitignored');

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('is not committed', $finding->detail);
    }

    public function test_passes_when_agents_md_is_committed_and_claude_md_is_untracked(): void
    {
        File::put(base_path('AGENTS.md'), 'stuff');
        $this->fakeTracked(['AGENTS.md']);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'AGENTS.md committed / CLAUDE.md gitignored');

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_warns_when_no_ecosystem_marker_in_claude_md(): void
    {
        $this->fakeTracked([]);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'ecosystem wiring-skill marker present');

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('has not been wired', $finding->detail);
    }

    public function test_passes_when_ecosystem_marker_present_in_claude_md(): void
    {
        File::put(base_path('CLAUDE.md'), "@AGENTS.md\n\n<!-- ecosystem:start -->\npointer\n<!-- ecosystem:end -->\n");
        $this->fakeTracked([]);

        $finding = $this->finding((new AgentsMdConventionAudit)->run(), 'ecosystem wiring-skill marker present');

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    /**
     * @param  list<string>  $trackedFiles
     */
    private function fakeTracked(array $trackedFiles): void
    {
        $fakes = [];
        foreach (['CLAUDE.md', 'AGENTS.md'] as $file) {
            $fakes["*ls-files*{$file}*"] = in_array($file, $trackedFiles, true)
                ? Process::result(exitCode: 0)
                : Process::result(exitCode: 1);
        }

        Process::fake($fakes);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function finding(array $findings, string $check): Finding
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check '{$check}'.");
    }
}
