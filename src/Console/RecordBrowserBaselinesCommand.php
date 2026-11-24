<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rushing\Popcorn\Registries\IsRegistry;
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
        {--for= : Keep only flows whose catalog registrant is this package (a composer name, e.g. splicewire/beam-accounts). A PACKAGE reference run; default (unset) commits every declared flow — the host pass. A key two packages both declare is kept for EACH, so it is committed into both their repos, never guessed.}
        {--into= : Write the committed baselines into THIS repo root (its tests/Browser/screenshots) instead of the running app base path; fresh captures are still read from the app base path. Requires --for. Default (unset) writes under the running app — the host pass.}
        {--check : Never write; report what WOULD change and exit 0. This is also forced off the record host.}';

    protected $description = 'Promote the browser suite\'s fresh captures into the committed screenshot baselines (record host only).';

    public function handle(FlowCatalog $catalog): int
    {
        // The fresh captures are ALWAYS read from the running app's base path — the Browser suite ran
        // here and wrote them. Only the committed-baseline DESTINATION (and the git repo its drift is
        // reported in) moves with --into; a package reference run reads the host's captures and writes
        // the package's own repo.
        $captureDir = base_path('tests/Browser/Screenshots');   // gitignored, the test writes here (capital S)

        // Resolve the target repo + registrant filter from --into/--for. Fail-closed: a mistyped package
        // or a missing target repo refuses rather than silently writing the wrong (or no) baselines.
        $target = $this->resolveTarget($catalog);

        if (is_int($target)) {
            return $target; // a guard already reported why and chose the exit code
        }

        [$baselineDir, $gitRoot, $allowedKeys] = $target;

        $manifest = $this->readManifest($captureDir);
        $writing = ! $this->option('check') && $this->isRecordHost();

        if ($manifest === null && $writing) {
            $this->components->error(
                "No run manifest at {$captureDir}/flow-results.jsonl — no evidence the Browser suite ran. "
                .'Run the Browser suite first; this command only promotes a fresh run\'s captures.'
            );

            return self::FAILURE;
        }

        $registrantSkipped = [];
        $captures = $this->gatherCaptures($captureDir, $catalog, $manifest, $allowedKeys, $registrantSkipped);

        if ($registrantSkipped !== []) {
            $this->components->info(
                count($registrantSkipped).' capture(s) skipped (not declared by '.$this->option('for').', not written): '
                .implode(', ', $registrantSkipped)
            );
        }

        if ($captures === []) {
            if ($allowedKeys !== null && $registrantSkipped !== []) {
                $this->components->warn(
                    'No promotable captures declared by '.$this->option('for').' in this run — nothing to record for that package.'
                );

                return self::SUCCESS;
            }

            $this->components->warn(
                "No promotable captures under {$captureDir}. Run the Browser suite first — it writes each flow's "
                .'capture + the run manifest there; this command only promotes them.'
            );

            return self::SUCCESS;
        }

        if (! $writing) {
            return $this->report($captures, $captureDir, $baselineDir, $manifest);
        }

        return $this->promote($captures, $captureDir, $baselineDir, $gitRoot);
    }

    /**
     * Resolve the committed-baseline destination, the git repo its drift is reported in, and the
     * registrant filter, from `--into`/`--for`.
     *
     * The HOST default (neither option) is byte-for-byte the historic behaviour: baselines under the
     * running app's base path, git drift in the app repo, every declared flow eligible. This default is
     * load-bearing — the host reference pass (ticket 26) relies on it being unchanged.
     *
     * A PACKAGE reference run names BOTH `--for=<vendor/name>` (whose flows) and `--into=<repo>` (that
     * package's own checkout). Three fail-closed guards, each a likely operator slip that must never
     * write the wrong baselines:
     *   - `--into` without `--for` refuses: dumping EVERY host flow into one package's repo is never
     *     intended, so the destination always names whose flows it receives.
     *   - a `--into` path that is not an existing directory refuses: a typo must not create a stray tree.
     *   - a `--for` package that declares no flow in this host's catalog refuses: a mistyped or
     *     not-installed package would otherwise silently promote nothing and look like success.
     *
     * @return array{0: string, 1: string, 2: ?list<string>}|int  [baselineDir, gitRoot, allowedKeys] or an exit code
     */
    private function resolveTarget(FlowCatalog $catalog): array|int
    {
        $for = $this->option('for');
        $for = is_string($for) && $for !== '' ? $for : null;

        $into = $this->option('into');
        $into = is_string($into) && $into !== '' ? rtrim($into, '/') : null;

        if ($into !== null && $for === null) {
            $this->components->error(
                '--into names a target repo but --for does not say whose flows it receives. A target repo '
                .'always commits exactly one package\'s declared flows; pass --for=<vendor/name>.'
            );

            return self::FAILURE;
        }

        if ($into !== null && ! is_dir($into)) {
            $this->components->error("--into target repo {$into} is not an existing directory — refusing to record.");

            return self::FAILURE;
        }

        $allowedKeys = null;

        if ($for !== null) {
            // keysBy() answers ABSOLUTE keys (root-prefixed, e.g. `beam.flows.readings.offering`); the
            // capture filename and the rest of this command speak the RELATIVE key (`readings.offering`),
            // so strip the catalog's own root. A key two packages both declare is returned by keysBy()
            // for EACH of them, so under --for=A it is kept for A and under --for=B kept for B — the same
            // flow is committed into BOTH package repos, never tie-broken, never guessed. (strval first:
            // keysBy returns Stringable RegistryKey OBJECTS, and a strict compare against a string never
            // matches — the silent-no-op that once rejected every capture, browser-journeys 17.)
            $root = $this->catalogRoot($catalog);

            // Fail CLOSED where the read-only audit merely degrades: without the catalog root we cannot
            // strip it, so every absolute keysBy() key would miss every relative capture key and the run
            // would skip everything and exit SUCCESS ("nothing to record") — a silent green that could mask
            // a real package's flows. A write must refuse instead. Unreachable while FlowCatalog hardcodes
            // its #[IsRegistry(root: 'beam.flows')]; this guards a future catalog that does not.
            if ($root === '') {
                $this->components->error(
                    "Cannot resolve the flow catalog's root key, so --for={$for} cannot be matched against the "
                    .'captured flows — refusing to record rather than silently committing nothing.'
                );

                return self::FAILURE;
            }

            $allowedKeys = array_values(array_unique(array_map(
                fn ($abs): string => $this->relativize((string) $abs, $root),
                $catalog->keysBy($for),
            )));

            if ($allowedKeys === []) {
                $this->components->error(
                    "--for={$for} declares no flow in this host's catalog — refusing to record. Check the "
                    .'package name and that the package is installed in this host.'
                );

                return self::FAILURE;
            }
        }

        $baselineDir = $into !== null
            ? $into.'/tests/Browser/screenshots'
            : base_path('tests/Browser/screenshots');

        $gitRoot = $into ?? base_path();

        return [$baselineDir, $gitRoot, $allowedKeys];
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
     * @param  list<string>|null  $allowedKeys  the registrant filter (relative keys), or null for no filter (host default)
     * @param  list<string>  $registrantSkipped  OUT: captures of a DECLARED flow dropped because another package declared it
     * @return list<array{name: string, fresh: bool}>
     */
    private function gatherCaptures(
        string $captureDir,
        FlowCatalog $catalog,
        ?array $manifest,
        ?array $allowedKeys = null,
        array &$registrantSkipped = [],
    ): array {
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

            // Registrant filter (--for): a DECLARED flow whose registrant is some OTHER package is listed
            // as skipped (never written), so a package reference run touches only its own baselines. The
            // allowed set is relative keys; a co-declared key is in the set for each declarer, so it is
            // NOT skipped here under either package's --for.
            if ($allowedKeys !== null && ! in_array($key, $allowedKeys, true)) {
                $registrantSkipped[] = $name;

                continue;
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
    private function promote(array $captures, string $captureDir, string $baselineDir, string $gitRoot): int
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
        $failed = [];

        foreach ($captures as $c) {
            if (! $c['fresh']) {
                continue; // stale but identical to its baseline — nothing to promote
            }

            $src = $captureDir.'/'.$c['name'].'.png';
            $dst = $baselineDir.'/'.$c['name'].'.png';

            // On a case-INSENSITIVE record volume the capture dir and the baseline dir fold to ONE physical file, so
            // the fresh capture ALREADY IS the committed baseline — count it structurally, without a self-copy that
            // PHP's same-file guard would reject anyway. The fold is detected by DEVICE+INODE identity, not realpath():
            // on macOS realpath() preserves the requested case ('…/Screenshots/x' vs '…/screenshots/x') and so does
            // NOT reveal the fold, but the two paths resolve to one inode. A FIRST-TIME baseline (dst not yet created)
            // or a case-SENSITIVE volume (src and dst are DISTINCT files) is NOT the same file, so it falls through to
            // a real copy that is VERIFIED: a failed move must fail closed, never be reported as promoted — that would
            // be the RegistryKey silent-success class again, on the actual target host.
            if ($this->isSameFile($src, $dst)) {
                $promoted++;

                continue;
            }

            if (! @copy($src, $dst)) {
                $failed[] = $c['name'];

                continue;
            }

            $promoted++;
        }

        if ($failed !== []) {
            $this->components->error(
                count($failed).' capture(s) could not be written to the committed baseline — NOT promoted. '
                .'Check the baseline directory is writable, then re-record. Failed: '.implode(', ', $failed)
            );

            return self::FAILURE;
        }

        $this->components->info("{$promoted} baseline(s) promoted on the record host.");
        $this->reportGitDrift($baselineDir, $gitRoot);

        return self::SUCCESS;
    }

    /**
     * Whether two paths are the SAME physical file (same device + inode) — the reliable fold test on a
     * case-insensitive volume, where realpath() keeps the requested case and cannot tell. False if either
     * path is absent (e.g. a first-time baseline whose $b does not exist yet) or the two are distinct files.
     */
    private function isSameFile(string $a, string $b): bool
    {
        if (! is_file($a) || ! is_file($b)) {
            return false;
        }

        $sa = @stat($a);
        $sb = @stat($b);

        return $sa !== false && $sb !== false
            && $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
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

    /**
     * Print `git status`/`git diff --stat` scoped to the committed baseline directory (done-when: drift on
     * the record host). Git runs in `$gitRoot` — the running app by default, or the `--into` target repo on
     * a package reference run, so the drift is reported in the repo that actually receives the commit.
     */
    private function reportGitDrift(string $baselineDir, string $gitRoot): void
    {
        $status = Process::path($gitRoot)->run(['git', 'status', '--porcelain', '--', $baselineDir]);

        if (trim($status->output()) === '') {
            $this->components->info('No baseline drift — committed screenshots already match the fresh captures.');

            return;
        }

        $this->line($status->output());
        $this->line(Process::path($gitRoot)->run(['git', 'diff', '--stat', '--', $baselineDir])->output());
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

    /* ---------------- registrant filter key-space (mirrors FlowDoctorAudit) ---------------- */

    /**
     * The catalog's own declared root (`beam.flows`), read off its {@see IsRegistry} attribute rather than
     * hardcoded, so a capture's RELATIVE key can be compared against the ABSOLUTE keys {@see FlowCatalog::keysBy()}
     * answers. `''` when the attribute cannot be read — a miss degrades to treating keys as already relative,
     * exactly as {@see \Splicewire\Beam\Doctor\FlowDoctorAudit} does.
     */
    private function catalogRoot(FlowCatalog $catalog): string
    {
        try {
            $attributes = (new \ReflectionClass($catalog))->getAttributes(IsRegistry::class);

            if ($attributes === []) {
                return '';
            }

            $root = $attributes[0]->newInstance()->root;

            return is_string($root) ? $root : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** Strip the catalog root prefix from an absolute key, yielding the relative key a capture is named by. */
    private function relativize(string $absolute, string $root): string
    {
        if ($root === '') {
            return $absolute;
        }

        $prefix = $root.'.';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }
}
