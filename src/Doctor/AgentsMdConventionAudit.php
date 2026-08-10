<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Support\Facades\Process;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * Drift check for the splicewire-ecosystem fleet convention (ticket 02): every family repo commits
 * `AGENTS.md` and gitignores `CLAUDE.md`, and gets wired to the ecosystem via the `setup-ecosystem`
 * skill, which stamps a marker block into the local `CLAUDE.md`. Advisory only — a repo that hasn't
 * adopted the convention yet isn't broken, just stale; this never fails the doctor exit code.
 *
 * Tracked-state (not just presence) matters for the first check, so it shells `git ls-files
 * --error-unmatch` rather than parsing `.gitignore` — a file can still be committed from before a
 * `.gitignore` entry was added, which a text-only check would miss.
 */
class AgentsMdConventionAudit implements DoctorAudit
{
    private const MARKER_START = '<!-- ecosystem:start -->';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [
            $this->agentsFileCommitted(),
            $this->wiringSkillMarkerPresent(),
        ];
    }

    private function agentsFileCommitted(): Finding
    {
        $check = 'AGENTS.md committed / CLAUDE.md gitignored';

        if ($this->tracked('CLAUDE.md')) {
            return Finding::warn(
                $check,
                'CLAUDE.md is git-tracked — the fleet convention (splicewire-ecosystem ticket 02) is '.
                'AGENTS.md-committed / CLAUDE.md-gitignored. Untrack it (git rm --cached CLAUDE.md) and add it to .gitignore.',
            );
        }

        if (! file_exists(base_path('AGENTS.md'))) {
            return Finding::warn(
                $check,
                'No AGENTS.md at the repo root — the fleet convention (splicewire-ecosystem ticket 02) expects '.
                'a committed AGENTS.md. Author one from the fixed self-ID header template, or run the setup-ecosystem skill.',
            );
        }

        if (! $this->tracked('AGENTS.md')) {
            return Finding::warn(
                $check,
                'AGENTS.md exists but is not committed — commit it so agents discover it consistently.',
            );
        }

        return Finding::pass(
            $check,
            'AGENTS.md is committed and CLAUDE.md is gitignored (or absent) — the fleet convention is followed.',
        );
    }

    private function wiringSkillMarkerPresent(): Finding
    {
        $check = 'ecosystem wiring-skill marker present';

        $claudeMd = base_path('CLAUDE.md');

        if (file_exists($claudeMd) && str_contains((string) file_get_contents($claudeMd), self::MARKER_START)) {
            return Finding::pass(
                $check,
                'CLAUDE.md carries the '.self::MARKER_START.' marker block — this repo has been wired via the setup-ecosystem skill.',
            );
        }

        return Finding::warn(
            $check,
            'No '.self::MARKER_START.'...<!-- ecosystem:end --> marker block found in CLAUDE.md — this repo has not '.
            'been wired to splicewire-ecosystem yet. Run the setup-ecosystem skill.',
        );
    }

    private function tracked(string $file): bool
    {
        return Process::path(base_path())->run(['git', 'ls-files', '--error-unmatch', $file])->successful();
    }
}
