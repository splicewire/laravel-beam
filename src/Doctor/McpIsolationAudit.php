<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * Verifies every registered Playwright MCP server is isolated. The arg-less default
 * (`npx @playwright/mcp@latest`) opens one fixed on-disk profile, so two concurrent Claude
 * sessions collide on Chromium's ProcessSingleton lock. The convention (references/browser-testing.md)
 * is a committed `.mcp.json` with `--isolated` (or a per-repo `--user-data-dir`). A registration
 * with neither — whether in `.mcp.json` or the global `~/.claude.json` — is the collision trap.
 *
 * Relocated from the satellite doctor (base/shell conformance is owned by beam:doctor). Generic:
 * carries no satellite/house naming. Pure: the command collects the registrations (source + full
 * command string); this only judges.
 *
 * @phpstan-type Registration array{source: string, command: string}
 */
class McpIsolationAudit
{
    /**
     * @param  list<array{source: string, command: string}>  $registrations
     *                                                                       Each entry's `command` is the full invocation string (command + args, sh -c body included).
     */
    public function run(array $registrations): Finding
    {
        $check = 'playwright MCP isolated';

        if ($registrations === []) {
            return Finding::inconclusive($check, 'no Playwright MCP server is registered — nothing to isolate.');
        }

        $unisolated = [];
        foreach ($registrations as $reg) {
            if (! $this->isIsolated($reg['command'])) {
                $unisolated[] = $reg['source'];
            }
        }

        if ($unisolated !== []) {
            return Finding::fail(
                $check,
                'Playwright MCP is registered without isolation in '.implode(', ', $unisolated).
                ' — add `--isolated` (or a per-repo `--user-data-dir`) so concurrent sessions do not collide.'
            );
        }

        return Finding::pass($check, 'every Playwright MCP registration is isolated (`--isolated` or per-repo `--user-data-dir`).');
    }

    private function isIsolated(string $command): bool
    {
        return str_contains($command, '--isolated') || str_contains($command, '--user-data-dir');
    }
}
