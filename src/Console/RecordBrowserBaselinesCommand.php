<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Testing\Flow;
use Splicewire\Beam\Testing\FlowOutcome;

/**
 * `php artisan splicewire:beam:browser:record-baselines` — promote the browser suite's fresh captures
 * into the COMMITTED screenshot baselines (browser-journeys 17). This command is the ONLY writer under
 * `tests/Browser/screenshots` (lower-case); a browser test only ever COMPARES against those baselines
 * and writes its own capture to the gitignored `tests/Browser/Screenshots` (capital S). See
 * {@see \Splicewire\Beam\Testing\FlowScreenshot} and {@see \Splicewire\Beam\Testing\FlowRun}.
 *
 * PROMOTE-ONLY, two-step. This command does NOT drive the browser — pest-plugin-browser (browser-journeys
 * 10/11) owns capture. The operator runs the Browser suite FIRST (it writes the capital-S captures and the
 * run manifest `flow-results.jsonl`), THEN runs this command to promote those captures. Driving the browser
 * here would duplicate the suite and couple recording to one runner.
 *
 * TWO-DIRECTORY SCHEME (PRD "Committed screenshot baselines", findings 4 + 5):
 *   - `tests/Browser/screenshots/<key>[--<role>]@<width>.png`  — committed baseline (this command writes it).
 *   - `tests/Browser/Screenshots/<same>.png`                   — gitignored fresh capture (the test writes it).
 * macOS is case-insensitive, so the two names are the SAME directory on the record host; the capital-S
 * path is the one pest-plugin-browser writes and `.gitignore` excludes, the lower-case path is the one
 * git tracks. The promote therefore reads a capture and writes the tracked baseline at the same spot —
 * a no-op in bytes when nothing drifted, a tracked diff when it did.
 *
 * RECORD-HOST GATE (done-when 4 + 5 — "a stray run on another Mac cannot overwrite baselines"):
 * writing a baseline requires BOTH
 *   (A) the determinism signature — {Darwin, arm64} + the lock-pinned Chromium — so a baseline is only
 *       ever written where captures are byte-deterministic; AND
 *   (B) the single-host DESIGNATION — the operator-set `BEAM_SCREENSHOT_RECORD_HOST` marker, present on
 *       exactly one canonical host (tots). (A) alone is necessary-not-sufficient: the fleet runs several
 *       arch-identical Macs with the same pinned Chromium, and two of them both writing would corrupt the
 *       byte-exact baselines. A host that fails EITHER gate does not write: it reports the drift it sees
 *       and exits 0 (an off-host run is informational, never a failure). The marker is documented for the
 *       operator in the ticket-19 runbook so the gate is never a silent refusal.
 *
 * STALENESS GUARD (a stale promote silently commits an OLD baseline — the thing this must not do):
 *   1. the run manifest `flow-results.jsonl` MUST exist — absent = no evidence the Browser suite ran, so
 *      the write path REFUSES (fail-closed) and tells the operator to run the suite first;
 *   2. only a capture whose `flow`+`role` has an `entered` manifest row is promoted — a `skipped-deviation`
 *      flow produced no real capture, and a stray PNG with no row is never promoted;
 *   3. a capture older than the latest run (its mtime predates the manifest beyond {@see RUN_WINDOW_SECONDS})
 *      that ALSO differs from its committed baseline aborts the whole promote — re-run the suite, never
 *      commit a leftover. (The manifest is append-only with no run-id/timestamp today, so layer 3 uses an
 *      mtime window; a run-id header in `flow-results.jsonl` would make it a hard boundary — see ticket 17
 *      follow-up.)
 */
class RecordBrowserBaselinesCommand extends Command
{
    /** The operator-set marker naming the one canonical record host (tots). Provisioned per the 19 runbook. */
    private const RECORD_HOST_MARKER = 'BEAM_SCREENSHOT_RECORD_HOST';

    /**
     * How far before the manifest's last write a capture may still count as "this run" (seconds). A Browser
     * suite run is bounded well under this; a capture older than it is a leftover from an earlier run. This
     * window is the stopgap until `flow-results.jsonl` carries a run-id/opened-at boundary (ticket 17 follow-up).
     */
    private const RUN_WINDOW_SECONDS = 3600;

    protected $signature = 'splicewire:beam:browser:record-baselines
        {--flow=* : Limit to these flow keys (default: every declared flow with a fresh capture).}
        {--check : Never write; report what WOULD change and exit 0. This is also forced off the record host.}';

    protected $description = 'Promote the browser suite\'s fresh captures into the committed screenshot baselines (record host only).';

    public function handle(FlowCatalog $catalog): int
    {
        $captureDir = base_path('tests/Browser/Screenshots');   // gitignored, the test writes here (capital S)
        $baselineDir = base_path('tests/Browser/screenshots');  // committed, this command writes here (lower-case)

        $manifest = $this->readManifest($captureDir);
        $writing = ! $this->option('check') && $this->isRecordHost();

        if ($manifest === null && $writing) {
            $this->components->error(
                "No run manifest at {$captureDir}/flow-results.jsonl — no evidence the Browser suite ran. "
                .'Run the Browser suite first; this command only promotes a fresh run\'s captures.'
            );

            return self::FAILURE;
        }

        $captures = $this->gatherCaptures($captureDir, $catalog, $manifest);

        if ($captures === []) {
            $this->components->warn(
                "No promotable captures under {$captureDir}. Run the Browser suite first — it writes each flow's "
                .'capture + the run manifest there; this command only promotes them.'
            );

            return self::SUCCESS;
        }

        if (! $writing) {
            return $this->report($captures, $captureDir, $baselineDir, $manifest);
        }

        return $this->promote($captures, $captureDir, $baselineDir);
    }

    /**
     * The run manifest: the set of `flow`+`role` keys the latest run ENTERED (so actually captured), plus the
     * file's mtime as the run marker. Null when the manifest is absent (no run evidence). A `skipped-deviation`
     * row is excluded — that flow produced no capture.
     *
     * @return array{entered: array<string, true>, mtime: int}|null
     */
    private function readManifest(string $captureDir): ?array
    {
        $path = $captureDir.'/flow-results.jsonl';

        if (! is_file($path)) {
            return null;
        }

        $entered = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);

            if (! is_array($row) || ($row['outcome'] ?? null) !== FlowOutcome::Entered->value) {
                continue; // skipped-deviation flows capture nothing; malformed lines are ignored
            }

            $entered[$this->manifestKey((string) ($row['flow'] ?? ''), $row['role'] ?? null)] = true;
        }

        return ['entered' => $entered, 'mtime' => (int) filemtime($path)];
    }

    /** The manifest lookup key for a flow+role pair (role is nullable). */
    private function manifestKey(string $flow, ?string $role): string
    {
        return $flow."\0".($role ?? '');
    }

    /**
     * The fresh captures to promote: every `.png` under the capture dir whose basename is a valid
     * `<key>[--<role>]@<width>` naming a DECLARED flow that the manifest shows was ENTERED, optionally narrowed
     * by `--flow`. Each is tagged `fresh` — false when it predates the latest run (see the staleness guard).
     *
     * @param  array{entered: array<string, true>, mtime: int}|null  $manifest
     * @return list<array{name: string, fresh: bool}>
     */
    private function gatherCaptures(string $captureDir, FlowCatalog $catalog, ?array $manifest): array
    {
        if (! is_dir($captureDir)) {
            return [];
        }

        $only = (array) $this->option('flow');
        $found = [];

        foreach (glob($captureDir.'/*.png') ?: [] as $path) {
            $name = basename($path, '.png');
            [$key, $role] = $this->partsOf($name);

            // Membership via has(), NOT in_array($key, $catalog->keys()): keys() returns Stringable
            // RegistryKey OBJECTS, so a strict string compare never matched and every capture was
            // silently rejected (browser-journeys 17). has() door-normalizes the key as register() does.
            if ($key === null || ! $catalog->has($key)) {
                continue; // not a flow baseline (results file, stray capture, undeclared key)
            }

            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            // Staleness layer 2: only a capture the latest run actually ENTERED is promotable.
            if ($manifest !== null && ! isset($manifest['entered'][$this->manifestKey($key, $role)])) {
                continue;
            }

            $fresh = $manifest === null
                ? true
                : filemtime($path) >= $manifest['mtime'] - self::RUN_WINDOW_SECONDS;

            $found[] = ['name' => $name, 'fresh' => $fresh];
        }

        usort($found, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $found;
    }

    /**
     * The flow key + role embedded in a baseline basename `<key>[--<role>]@<width>`, or `[null, null]` if the
     * name is not one.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function partsOf(string $name): array
    {
        if (! str_contains($name, '@')) {
            return [null, null];
        }

        $beforeWidth = substr($name, 0, (int) strrpos($name, '@'));

        if (str_contains($beforeWidth, '--')) {
            $at = (int) strpos($beforeWidth, '--');
            $key = substr($beforeWidth, 0, $at);
            $role = substr($beforeWidth, $at + 2);
        } else {
            $key = $beforeWidth;
            $role = null;
        }

        try {
            Flow::assertValidKey($key);
        } catch (\InvalidArgumentException) {
            return [null, null];
        }

        return [$key, $role];
    }

    /**
     * WRITE path (record host): promote each FRESH capture over its committed baseline, then report the git
     * drift that produced. Staleness layer 3 is fail-closed — if any capture predates the latest run AND
     * differs from its baseline, the whole promote aborts (writing nothing) so an old baseline is never
     * silently committed.
     *
     * @param  list<array{name: string, fresh: bool}>  $captures
     */
    private function promote(array $captures, string $captureDir, string $baselineDir): int
    {
        if (! is_dir($baselineDir) && ! @mkdir($baselineDir, 0775, true) && ! is_dir($baselineDir)) {
            $this->components->error("Could not create baseline directory {$baselineDir}.");

            return self::FAILURE;
        }

        $staleDiffering = [];

        foreach ($captures as $c) {
            if ($c['fresh']) {
                continue;
            }

            $baseline = $baselineDir.'/'.$c['name'].'.png';

            if (! is_file($baseline) || sha1_file($baseline) !== sha1_file($captureDir.'/'.$c['name'].'.png')) {
                $staleDiffering[] = $c['name'];
            }
        }

        if ($staleDiffering !== []) {
            $this->components->error(
                count($staleDiffering).' capture(s) predate the latest run but differ from their baseline — '
                .'refusing to promote a stale baseline. Re-run the Browser suite, then record. Stale: '
                .implode(', ', $staleDiffering)
            );

            return self::FAILURE;
        }

        $promoted = 0;

        foreach ($captures as $c) {
            if (! $c['fresh']) {
                continue; // stale but identical to its baseline — nothing to promote
            }

            copy($captureDir.'/'.$c['name'].'.png', $baselineDir.'/'.$c['name'].'.png');
            $promoted++;
        }

        $this->components->info("{$promoted} baseline(s) promoted on the record host.");
        $this->reportGitDrift($baselineDir);

        return self::SUCCESS;
    }

    /**
     * COMPARE path (off the record host, or `--check`): byte-compare each fresh capture against its committed
     * baseline, print changed / new / unchanged / stale-refused, and exit 0 — NEVER fail on appearance
     * (done-when 5: the tolerance diff engine is out of scope, so a comparison is EXACT bytes and is
     * informational only). A stale-differing capture is shown as it WOULD be refused on the record host.
     *
     * @param  list<array{name: string, fresh: bool}>  $captures
     * @param  array{entered: array<string, true>, mtime: int}|null  $manifest
     */
    private function report(array $captures, string $captureDir, string $baselineDir, ?array $manifest): int
    {
        if (! $this->isRecordHost()) {
            $this->components->warn(
                'Not the designated record host — refusing to write baselines ('.$this->gateReason().
                '). Reporting drift only; exit unaffected.'
            );
        }

        if ($manifest === null) {
            $this->components->warn(
                'No run manifest (flow-results.jsonl) — cannot confirm these captures are from a fresh run; '
                .'reporting on whatever is present.'
            );
        }

        [$changed, $new, $same, $stale] = [0, 0, 0, 0];

        foreach ($captures as $c) {
            $name = $c['name'];
            $baseline = $baselineDir.'/'.$name.'.png';
            $differs = ! is_file($baseline) || sha1_file($baseline) !== sha1_file($captureDir.'/'.$name.'.png');

            if (! $c['fresh'] && $differs) {
                $stale++;
                $this->line("  <fg=magenta>stale</>     {$name}  (predates latest run; would be REFUSED)");
            } elseif (! is_file($baseline)) {
                $new++;
                $this->line("  <fg=yellow>new</>       {$name}");
            } elseif ($differs) {
                $changed++;
                $this->line("  <fg=red>changed</>   {$name}");
            } else {
                $same++;
            }
        }

        $this->components->info(
            "Drift: {$changed} changed, {$new} new, {$same} unchanged, {$stale} stale-refused (".count($captures).' captures).'
        );

        return self::SUCCESS;
    }

    /** Print `git status`/`git diff --stat` scoped to the committed baseline directory (done-when: drift on the record host). */
    private function reportGitDrift(string $baselineDir): void
    {
        $status = Process::path(base_path())->run(['git', 'status', '--porcelain', '--', $baselineDir]);

        if (trim($status->output()) === '') {
            $this->components->info('No baseline drift — committed screenshots already match the fresh captures.');

            return;
        }

        $this->line($status->output());
        $this->line(Process::path(base_path())->run(['git', 'diff', '--stat', '--', $baselineDir])->output());
    }

    /** WRITE gate: the determinism signature (A) AND the single-host designation (B). Both required. */
    private function isRecordHost(): bool
    {
        return $this->determinismSignature() && $this->designated();
    }

    /** (A) Byte-determinism signature: a macOS arm64 host with the lock-pinned Chromium. */
    private function determinismSignature(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64' && $this->hasPinnedChromium();
    }

    /**
     * (A, Chromium leg) the EXACT lock-pinned Chromium revision is installed.
     *
     * Resolves the revision the committed Playwright install pins — the `chromium` entry's `revision` in
     * the installed `playwright-core` browsers registry, itself pinned by the app's committed JS lock — and
     * asserts the matching `chromium-<revision>` browser directory is present in a Playwright browsers root.
     * An arch-twin (Darwin+arm64) carrying a DIFFERENT Chromium revision therefore fails (A) and cannot
     * promote divergent baselines. Fails closed: an unresolvable registry or a missing exact revision dir is
     * NOT a record host.
     */
    private function hasPinnedChromium(): bool
    {
        $revision = $this->pinnedChromiumRevision();

        if ($revision === null) {
            return false;
        }

        foreach ($this->browserRoots() as $dir) {
            if (is_dir($dir.'/chromium-'.$revision)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exact Chromium revision the committed Playwright install pins, read from the resolved
     * `playwright-core` browsers registry (the `revision` of its `chromium` browser). The installed
     * Playwright version is itself pinned by the app's committed JS lock, so this is the lock-pinned
     * revision. Returns null — fail closed — when the registry cannot be resolved or parsed.
     */
    private function pinnedChromiumRevision(): ?string
    {
        foreach (['playwright-core', 'playwright'] as $pkg) {
            $registry = base_path("node_modules/{$pkg}/browsers.json");

            if (! is_file($registry)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($registry), true);

            if (! is_array($data) || ! is_array($data['browsers'] ?? null)) {
                continue;
            }

            foreach ($data['browsers'] as $browser) {
                if (is_array($browser) && ($browser['name'] ?? null) === 'chromium') {
                    $revision = $browser['revision'] ?? null;

                    return (is_string($revision) || is_int($revision)) ? (string) $revision : null;
                }
            }
        }

        return null;
    }

    /**
     * The Playwright browsers roots to look in: the `PLAYWRIGHT_BROWSERS_PATH` override first, then the
     * per-OS default cache (macOS `~/Library/Caches/ms-playwright`, Linux `~/.cache/ms-playwright`). Both
     * default candidates are offered regardless of OS so a misdetected home still resolves; the determinism
     * signature already constrains the host to Darwin/arm64.
     *
     * @return list<string>
     */
    private function browserRoots(): array
    {
        $home = $_SERVER['HOME'] ?? '';

        return array_values(array_filter([
            getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null,
            $home !== '' ? $home.'/Library/Caches/ms-playwright' : null,
            $home !== '' ? $home.'/.cache/ms-playwright' : null,
        ]));
    }

    /** (B) The operator-set single-host designation marker is present and truthy (set on tots only). */
    private function designated(): bool
    {
        return filter_var(env(self::RECORD_HOST_MARKER), FILTER_VALIDATE_BOOLEAN);
    }

    /** Which gate leg failed, for the off-host message (never a silent refusal). */
    private function gateReason(): string
    {
        $missing = [];

        if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
            $missing[] = 'not macOS/arm64';
        } elseif (! $this->hasPinnedChromium()) {
            $missing[] = 'no lock-pinned Chromium';
        }

        if (! $this->designated()) {
            $missing[] = self::RECORD_HOST_MARKER.' not set';
        }

        return implode('; ', $missing);
    }
}
