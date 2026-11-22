<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Testing\Flow;

/**
 * `php artisan splicewire:beam:browser:record-baselines` — promote the browser suite's fresh captures
 * into the COMMITTED screenshot baselines (browser-journeys 17). This command is the ONLY writer under
 * `tests/Browser/screenshots` (lower-case); a browser test only ever COMPARES against those baselines
 * and writes its own capture to the gitignored `tests/Browser/Screenshots` (capital S). See
 * {@see \Splicewire\Beam\Testing\FlowScreenshot} and {@see \Splicewire\Beam\Testing\FlowRun}.
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
 */
class RecordBrowserBaselinesCommand extends Command
{
    /** The operator-set marker naming the one canonical record host (tots). Provisioned per the 19 runbook. */
    private const RECORD_HOST_MARKER = 'BEAM_SCREENSHOT_RECORD_HOST';

    protected $signature = 'splicewire:beam:browser:record-baselines
        {--flow=* : Limit to these flow keys (default: every declared flow with a fresh capture).}
        {--check : Never write; report what WOULD change and exit 0. This is also forced off the record host.}';

    protected $description = 'Promote the browser suite\'s fresh captures into the committed screenshot baselines (record host only).';

    public function handle(FlowCatalog $catalog): int
    {
        $captureDir = base_path('tests/Browser/Screenshots');   // gitignored, the test writes here (capital S)
        $baselineDir = base_path('tests/Browser/screenshots');  // committed, this command writes here (lower-case)

        $captures = $this->gatherCaptures($captureDir, $catalog);

        if ($captures === []) {
            $this->components->warn(
                "No fresh captures under {$captureDir}. Run the browser suite first — it writes each flow's "
                .'capture there; this command only promotes them.'
            );

            return self::SUCCESS;
        }

        $writing = ! $this->option('check') && $this->isRecordHost();

        if (! $writing) {
            return $this->report($captures, $captureDir, $baselineDir);
        }

        return $this->promote($captures, $captureDir, $baselineDir);
    }

    /**
     * The fresh captures to consider: every `.png` under the capture dir whose basename is a valid
     * `<key>[--<role>]@<width>` naming a DECLARED flow (a stray file is skipped, never promoted), optionally
     * narrowed by `--flow`.
     *
     * @return list<string> capture basenames WITHOUT the `.png` extension
     */
    private function gatherCaptures(string $captureDir, FlowCatalog $catalog): array
    {
        if (! is_dir($captureDir)) {
            return [];
        }

        $only = (array) $this->option('flow');
        $declared = $catalog->keys();
        $found = [];

        foreach (glob($captureDir.'/*.png') ?: [] as $path) {
            $name = basename($path, '.png');
            $key = $this->keyOf($name);

            if ($key === null || ! in_array($key, $declared, true)) {
                continue; // not a flow baseline (results file, stray capture, undeclared key)
            }

            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            $found[] = $name;
        }

        sort($found);

        return $found;
    }

    /** The flow key embedded in a baseline basename `<key>[--<role>]@<width>`, or null if the name is not one. */
    private function keyOf(string $name): ?string
    {
        if (! str_contains($name, '@')) {
            return null;
        }

        $beforeWidth = substr($name, 0, strrpos($name, '@'));
        $key = str_contains($beforeWidth, '--') ? substr($beforeWidth, 0, strpos($beforeWidth, '--')) : $beforeWidth;

        try {
            Flow::assertValidKey($key);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $key;
    }

    /**
     * WRITE path (record host): copy each fresh capture over its committed baseline, then report the git
     * drift that produced. The copy is the only mutation of the tracked baseline directory anywhere in the
     * suite.
     */
    private function promote(array $captures, string $captureDir, string $baselineDir): int
    {
        if (! is_dir($baselineDir) && ! @mkdir($baselineDir, 0775, true) && ! is_dir($baselineDir)) {
            $this->components->error("Could not create baseline directory {$baselineDir}.");

            return self::FAILURE;
        }

        foreach ($captures as $name) {
            copy($captureDir.'/'.$name.'.png', $baselineDir.'/'.$name.'.png');
        }

        $this->components->info(count($captures).' baseline(s) promoted on the record host.');
        $this->reportGitDrift($baselineDir);

        return self::SUCCESS;
    }

    /**
     * COMPARE path (off the record host, or `--check`): byte-compare each fresh capture against its committed
     * baseline, print changed / new / unchanged, and exit 0 — NEVER fail on appearance (done-when 5: the
     * tolerance diff engine is out of scope, so a comparison is EXACT bytes and is informational only).
     */
    private function report(array $captures, string $captureDir, string $baselineDir): int
    {
        if (! $this->isRecordHost()) {
            $this->components->warn(
                'Not the designated record host — refusing to write baselines ('.$this->gateReason().
                '). Reporting drift only; exit unaffected.'
            );
        }

        [$changed, $new, $same] = [0, 0, 0];

        foreach ($captures as $name) {
            $baseline = $baselineDir.'/'.$name.'.png';
            $capture = $captureDir.'/'.$name.'.png';

            if (! is_file($baseline)) {
                $new++;
                $this->line("  <fg=yellow>new</>       {$name}");
            } elseif (sha1_file($baseline) !== sha1_file($capture)) {
                $changed++;
                $this->line("  <fg=red>changed</>   {$name}");
            } else {
                $same++;
            }
        }

        $this->components->info("Drift: {$changed} changed, {$new} new, {$same} unchanged (".count($captures).' captures).');

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
     * (A, Chromium leg) the lock-pinned Chromium is installed.
     *
     * TODO(17, before land): tighten from "a pinned browser is present" to "the EXACT revision the committed
     * pest-plugin-browser / Playwright lock pins" — resolve the locked revision and assert the resolved
     * browser path carries it, so a host with a DIFFERENT Chromium (non-deterministic bytes) fails (A).
     * Fails closed: an unresolvable browser path is NOT a record host.
     */
    private function hasPinnedChromium(): bool
    {
        foreach ([getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null, $_SERVER['HOME'].'/.cache/ms-playwright'] as $dir) {
            if ($dir !== null && is_dir($dir) && (glob($dir.'/chromium-*') ?: []) !== []) {
                return true;
            }
        }

        return false;
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
