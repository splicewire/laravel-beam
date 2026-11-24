<?php

namespace Splicewire\Beam\Tests\Console;

use Splicewire\Beam\Flows\DeclaresFlows;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Testing\FlowOutcome;
use Splicewire\Beam\Tests\TestCase;

/**
 * browser-journeys 17 — the record command (`splicewire:beam:browser:record-baselines`) genuinely
 * promotes DECLARED-flow captures on the designated record host, gates every other host out of the
 * write path, and honours the manifest staleness guard. These tests EXERCISE the command against a
 * fixture app tree (fresh captures, a run manifest, a pinned-Chromium fixture, the designation marker)
 * — they are not existence assertions.
 *
 * They are the test that catches — and originally DID catch — the dead declared-flow filter:
 * {@see \Splicewire\Beam\Console\RecordBrowserBaselinesCommand::gatherCaptures()} strict-compared a
 * STRING flow key against `FlowCatalog::keys()`, which returns Stringable `RegistryKey` OBJECTS, so no
 * capture ever matched and the command exited SUCCESS recording nothing. {@see test_promotes_a_declared_flow_capture_on_the_record_host}
 * goes RED against that bug (no capture promoted) and GREEN once the membership check uses `$catalog->has()`.
 *
 * HOST ASSUMPTION (honest): the write-path legs require the command's determinism signature — Darwin +
 * arm64 + a lock-pinned Chromium. The OS/arch leg is the host itself (it is not in-process toggleable),
 * so the class skips off a non-Darwin/arm64 host rather than passing vacuously. The two IN-PROCESS gate
 * legs — the operator DESIGNATION marker and the pinned-Chromium fixture — are driven directly.
 *
 * CASE-SENSITIVITY NOTE: on the macOS case-insensitive record host, `tests/Browser/Screenshots` (capture)
 * and `tests/Browser/screenshots` (baseline) fold to the SAME inode, so a capture can never BYTE-DIFFER
 * from its baseline there and promote() takes its structural skip-and-count (same device+inode) path — the
 * fold is detected by physical-file identity, since macOS realpath() keeps the requested case and cannot tell. The promote()-specific case-SENSITIVE branches — the real copy() that MOVES bytes into a distinct
 * baseline dir, and the fail-closed guard when that copy() returns false — ARE exercised, by mounting a
 * genuine case-sensitive scratch volume ({@see caseSensitiveBaseOrSkip}); those two tests skip only on a host
 * where such a volume can't be provisioned. The remaining differ-gated branches — staleness layer-3's ABORT
 * (stale AND differs) and `report()`'s changed/new counts — stay outside this suite (not in the amend's narrow
 * scope); every other branch reachable on a case-insensitive host is exercised below, including layer-3's skip.
 */
class RecordBrowserBaselinesCommandTest extends TestCase
{
    private const MARKER = 'BEAM_SCREENSHOT_RECORD_HOST';

    /** A synthetic Chromium revision that will not collide with any real ms-playwright cache dir. */
    private const REV = '7777777';

    /** Mirrors the command's RUN_WINDOW_SECONDS; a capture older than (manifest mtime - this) is stale. */
    private const RUN_WINDOW_SECONDS = 3600;

    private string $base;          // fake app base (drives base_path() via useBasePath)

    private string $shots;         // tests/Browser/Screenshots === screenshots on this case-insensitive host

    private string $browsersRoot;  // PLAYWRIGHT_BROWSERS_PATH fixture root

    private ?string $csImage = null;   // a mounted case-SENSITIVE scratch image (distinct-dir tests), detached in tearDown

    private ?string $csMount = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
            $this->markTestSkipped('record-host gate tests assume the Darwin/arm64 record host (determinism leg A).');
        }

        $this->base = sys_get_temp_dir().'/bj17-rec-'.bin2hex(random_bytes(6));
        $this->shots = $this->base.'/tests/Browser/Screenshots';
        $this->browsersRoot = $this->base.'/.ms-playwright';

        @mkdir($this->shots, 0775, true);
        @mkdir($this->browsersRoot.'/chromium-'.self::REV, 0775, true);   // pinned-Chromium leg satisfied by default
        @mkdir($this->base.'/node_modules/playwright-core', 0775, true);
        file_put_contents(
            $this->base.'/node_modules/playwright-core/browsers.json',
            json_encode(['browsers' => [['name' => 'chromium', 'revision' => self::REV]]]),
        );
        putenv('PLAYWRIGHT_BROWSERS_PATH='.$this->browsersRoot);

        $this->designate(false);                 // off by default; each write-path test opts in
        $this->app->setBasePath($this->base);    // the command reads its dirs off base_path()

        // Two DECLARED flows so the declared-flow filter and --flow narrowing have something to key on.
        $this->app->make(FlowCatalog::class)->declare(Bj17TestFlows::class, by: 'splicewire/laravel-beam');
    }

    protected function tearDown(): void
    {
        $this->designate(false);
        putenv('PLAYWRIGHT_BROWSERS_PATH');
        $this->detachCaseSensitive();
        $this->rrmdir($this->base);

        parent::tearDown();
    }

    /**
     * THE red→green test. A declared + entered capture is promoted; an undeclared capture and a
     * non-flow file are ignored. RED under the `in_array(string, [RegistryKey])` bug (gather returns
     * [], command warns "No promotable captures", nothing promoted); GREEN once membership uses has().
     */
    public function test_promotes_a_declared_flow_capture_on_the_record_host(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');  // declared + entered  → promoted
        $this->writeCapture('teams.unknown@1280');      // undeclared          → ignored
        $this->writeCapture('noatsign');                // not a baseline name → ignored

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();

        // promote() over the same inode is a safe no-op — the committed baseline is intact, not truncated.
        $this->assertSame(
            'PNG-readings.offering@1280',
            file_get_contents($this->shots.'/readings.offering@1280.png'),
        );
    }

    /**
     * Staleness layer 2 (entered-only) AND the declared filter together: a DECLARED-but-not-entered flow
     * and an UNDECLARED flow are both excluded; only the declared + entered capture is promoted.
     */
    public function test_excludes_undeclared_and_unentered_captures(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);     // subscribe.door NOT entered
        $this->writeCapture('readings.offering@1280');   // declared + entered       → promoted
        $this->writeCapture('subscribe.door@1280');      // declared, NOT entered    → layer-2 excludes
        $this->writeCapture('teams.unknown@1280');       // undeclared               → excluded

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();
    }

    /** Staleness layer 1: on the record host a missing run manifest fails closed (no silent stale promote). */
    public function test_missing_manifest_fails_closed_on_the_record_host(): void
    {
        $this->designate(true);
        $this->writeCapture('readings.offering@1280');   // no flow-results.jsonl written

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('No run manifest')
            ->assertFailed();
    }

    /**
     * Staleness layer 3 (reachable half): a capture older than the run window is NOT promoted. Since the
     * capture and baseline are one inode here it cannot byte-differ, so it is skipped (not aborted) — the
     * `! fresh` continue branch. (The abort branch needs a case-sensitive volume; see class docblock.)
     */
    public function test_stale_capture_is_skipped_not_promoted(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);                 // manifest mtime ~ now
        $this->writeCapture('readings.offering@1280');
        touch($this->shots.'/readings.offering@1280.png', time() - (self::RUN_WINDOW_SECONDS * 2)); // predates run

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('0 baseline(s) promoted')
            ->assertSuccessful();
    }

    /**
     * Gate leg B (DESIGNATION): the determinism signature is satisfied (fixture Chromium present) but the
     * operator marker is unset → the command refuses to write and reports only, exit 0.
     */
    public function test_without_designation_reports_only_and_never_writes(): void
    {
        $this->designate(false);                         // determinism leg A still satisfied by the fixture
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('Not the designated record host')
            ->expectsOutputToContain('Drift:')
            ->assertSuccessful();
    }

    /**
     * Gate leg A (pinned Chromium): the marker IS set, but the pinned-Chromium revision dir is absent →
     * the determinism signature fails, so the command refuses to write and reports only, exit 0.
     */
    public function test_without_pinned_chromium_reports_only_and_never_writes(): void
    {
        $this->designate(true);                          // designation leg B satisfied
        @rmdir($this->browsersRoot.'/chromium-'.self::REV);  // revision known from browsers.json, dir gone → fail-closed
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('Not the designated record host')
            ->assertSuccessful();
    }

    /** `--flow` narrows promotion to the named key even when several declared+entered captures are present. */
    public function test_flow_option_narrows_promotion(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering', 'subscribe.door']);
        $this->writeCapture('readings.offering@1280');
        $this->writeCapture('subscribe.door@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', ['--flow' => ['readings.offering']])
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();
    }

    /** `--check` never writes, even on the record host — it reports drift and exits 0. */
    public function test_check_reports_without_writing_on_the_record_host(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', ['--check' => true])
            ->expectsOutputToContain('Drift:')
            ->assertSuccessful();
    }

    /**
     * DISTINCT-DIR promote (the real copy() path). On a case-SENSITIVE record volume `tests/Browser/Screenshots`
     * (capture) and `tests/Browser/screenshots` (baseline) are TWO directories, so promote() takes the real
     * copy()+return-check branch — the branch the fold host can never reach. Here the copy succeeds and the
     * committed baseline receives the capture bytes. Skips if a case-sensitive volume can't be provisioned.
     */
    public function test_promotes_into_a_distinct_baseline_dir_on_a_case_sensitive_volume(): void
    {
        $base = $this->caseSensitiveBaseOrSkip();
        $this->seedRecordHost($base, ['readings.offering'], ['readings.offering@1280']);

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();

        // src and dst are genuinely distinct files here — the baseline must have RECEIVED the capture bytes.
        $this->assertSame(
            'PNG-readings.offering@1280',
            file_get_contents($base.'/tests/Browser/screenshots/readings.offering@1280.png'),
        );
    }

    /**
     * DISTINCT-DIR copy FAILURE fails closed (the core of the amend). On a case-SENSITIVE volume with an
     * unwritable baseline dir, copy() genuinely returns false; the command must NOT report the capture as
     * promoted and must exit non-zero. RED before the amend (promote() incremented unconditionally and
     * exited 0, silently reporting a move that never happened); GREEN after (checked copy() → fail-closed).
     */
    public function test_copy_failure_is_not_promoted_and_exits_nonzero_on_a_case_sensitive_volume(): void
    {
        $base = $this->caseSensitiveBaseOrSkip();
        $this->seedRecordHost($base, ['readings.offering'], ['readings.offering@1280']);

        // Pre-create the committed baseline dir and make it unwritable so the real copy() fails.
        $baselineDir = $base.'/tests/Browser/screenshots';
        @mkdir($baselineDir, 0775, true);
        chmod($baselineDir, 0500);

        $this->artisan('splicewire:beam:browser:record-baselines')
            ->doesntExpectOutputToContain('1 baseline(s) promoted')
            ->expectsOutputToContain('could not be written')
            ->assertFailed();
    }

    // ---- browser-journeys 25: registrant filter (--for) + target repo (--into) ------------------

    /**
     * `--for=<package>` keeps ONLY flows that package declared; a declared flow owned by ANOTHER package
     * is listed as skipped and never written. `--into=<repo>` writes the committed baseline into that
     * repo's tree, not the running app base path. Together: a package reference run commits only its own
     * flows, into its own repo. (A distinct --into dir forces the real copy() even on this fold host.)
     */
    public function test_for_filters_to_the_named_registrants_flows_and_into_writes_that_repo(): void
    {
        $this->designate(true);
        $this->app->make(FlowCatalog::class)->declare(Bj25AccountsFlows::class, by: 'splicewire/beam-accounts');

        $into = $this->base.'/accounts-repo';   // distinct path (not the base_path fold) → real copy()
        @mkdir($into, 0775, true);

        $this->writeManifest(['readings.offering', 'account.security']);
        $this->writeCapture('readings.offering@1280');   // splicewire/laravel-beam → skipped by --for
        $this->writeCapture('account.security@1280');    // splicewire/beam-accounts → promoted into $into

        $this->artisan('splicewire:beam:browser:record-baselines', [
            '--for' => 'splicewire/beam-accounts',
            '--into' => $into,
        ])
            ->expectsOutputToContain('skipped (not declared by splicewire/beam-accounts')
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();

        // Written into the TARGET repo (distinct from base_path), and the other package's flow was not.
        $this->assertSame(
            'PNG-account.security@1280',
            file_get_contents($into.'/tests/Browser/screenshots/account.security@1280.png'),
        );
        $this->assertFileDoesNotExist($into.'/tests/Browser/screenshots/readings.offering@1280.png');
    }

    /**
     * THE two-package test (the land row's load-bearing read of the owner ruling): a flow key DECLARED
     * BY BOTH packages SUPERSEDES — the LATER registrant wins, so the baseline is committed ONLY into
     * the winning package's repo, never duplicated into the superseded one. `keysBy()` answers the key
     * for the winner alone (the displaced entry is supersession history, never read), so under
     * `--for=<winner>` the shared key is kept and under `--for=<superseded>` it is not. Each repo still
     * receives its OWN exclusive flow and NOT the other's.
     *
     * Registration order is pinned HERE by declare() call sequence: accounts declares first, commerce
     * declares `shared.widget` LAST, so commerce is the later registrant and the winner. (A real host's
     * order is provider registration order — installed.json then app providers — which is measured,
     * not asserted; see the land row.)
     */
    public function test_a_superseded_flow_is_committed_only_into_the_winning_package_repo(): void
    {
        $this->designate(true);
        $catalog = $this->app->make(FlowCatalog::class);
        $catalog->declare(Bj25AccountsFlows::class, by: 'splicewire/beam-accounts');   // account.security + shared.widget (FIRST → superseded)
        $catalog->declare(Bj25CommerceFlows::class, by: 'splicewire/beam-commerce');   // commerce.cart   + shared.widget (LAST  → winner)

        $accountsRepo = $this->base.'/accounts-repo';
        $commerceRepo = $this->base.'/commerce-repo';
        @mkdir($accountsRepo, 0775, true);
        @mkdir($commerceRepo, 0775, true);

        $this->writeManifest(['account.security', 'commerce.cart', 'shared.widget']);
        $this->writeCapture('account.security@1280');
        $this->writeCapture('commerce.cart@1280');
        $this->writeCapture('shared.widget@1280');       // declared by BOTH; commerce wins it

        // Accounts (the SUPERSEDED declarer) → accounts repo gets its exclusive flow ONLY; the shared
        // key is no longer declared by accounts, so it is skipped, not committed.
        $this->artisan('splicewire:beam:browser:record-baselines', [
            '--for' => 'splicewire/beam-accounts',
            '--into' => $accountsRepo,
        ])
            ->expectsOutputToContain('skipped (not declared by splicewire/beam-accounts')
            ->expectsOutputToContain('1 baseline(s) promoted')
            ->assertSuccessful();

        // Commerce (the WINNER) → commerce repo gets its exclusive flow AND the superseded shared key.
        $this->artisan('splicewire:beam:browser:record-baselines', [
            '--for' => 'splicewire/beam-commerce',
            '--into' => $commerceRepo,
        ])
            ->expectsOutputToContain('2 baseline(s) promoted')
            ->assertSuccessful();

        // The superseded flow is ONLY in the winner's repo — never duplicated into the displaced one.
        $this->assertFileExists($commerceRepo.'/tests/Browser/screenshots/shared.widget@1280.png');
        $this->assertFileDoesNotExist($accountsRepo.'/tests/Browser/screenshots/shared.widget@1280.png');

        // Each repo got its OWN exclusive flow...
        $this->assertFileExists($accountsRepo.'/tests/Browser/screenshots/account.security@1280.png');
        $this->assertFileExists($commerceRepo.'/tests/Browser/screenshots/commerce.cart@1280.png');

        // ...and NOT the other package's exclusive flow.
        $this->assertFileDoesNotExist($accountsRepo.'/tests/Browser/screenshots/commerce.cart@1280.png');
        $this->assertFileDoesNotExist($commerceRepo.'/tests/Browser/screenshots/account.security@1280.png');
    }

    /** `--into` without `--for` fails closed — a target repo must name whose flows it receives. */
    public function test_into_without_for_fails_closed(): void
    {
        $this->designate(true);
        $into = $this->base.'/some-repo';
        @mkdir($into, 0775, true);
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', ['--into' => $into])
            ->expectsOutputToContain('--into names a target repo but --for')
            ->assertFailed();

        $this->assertFileDoesNotExist($into.'/tests/Browser/screenshots/readings.offering@1280.png');
    }

    /** `--into` naming a path that is not an existing directory fails closed — a typo creates no stray tree. */
    public function test_into_missing_directory_fails_closed(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', [
            '--for' => 'splicewire/laravel-beam',
            '--into' => $this->base.'/no-such-repo',
        ])
            ->expectsOutputToContain('is not an existing directory')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->base.'/no-such-repo');
    }

    /** `--for` naming a package that declares no flow in this catalog fails closed — never a silent empty success. */
    public function test_for_unknown_package_fails_closed(): void
    {
        $this->designate(true);
        $this->writeManifest(['readings.offering']);
        $this->writeCapture('readings.offering@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', ['--for' => 'splicewire/not-installed'])
            ->expectsOutputToContain('declares no flow')
            ->assertFailed();
    }

    /**
     * Off the record host (determinism/designation gate fails), a `--for`/`--into` package run still writes
     * NOTHING — it reports drift only and exits 0, exactly like the host default (done-when 2: a stray run on
     * another Mac cannot overwrite baselines, target repo or not).
     */
    public function test_for_into_off_record_host_writes_nothing(): void
    {
        $this->designate(false);                         // determinism leg A still satisfied by the fixture
        $this->app->make(FlowCatalog::class)->declare(Bj25AccountsFlows::class, by: 'splicewire/beam-accounts');

        $into = $this->base.'/accounts-repo';
        @mkdir($into, 0775, true);
        $this->writeManifest(['account.security']);
        $this->writeCapture('account.security@1280');

        $this->artisan('splicewire:beam:browser:record-baselines', [
            '--for' => 'splicewire/beam-accounts',
            '--into' => $into,
        ])
            ->expectsOutputToContain('Not the designated record host')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($into.'/tests/Browser/screenshots/account.security@1280.png');
    }

    // ---- fixtures -------------------------------------------------------------------------------

    private function designate(bool $on): void
    {
        if ($on) {
            $_SERVER[self::MARKER] = '1';
            $_ENV[self::MARKER] = '1';
            putenv(self::MARKER.'=1');
        } else {
            unset($_SERVER[self::MARKER], $_ENV[self::MARKER]);
            putenv(self::MARKER);
        }
    }

    /** @param  list<string>  $flows  flow keys (role null) the manifest records as ENTERED. */
    private function writeManifest(array $flows): void
    {
        $lines = array_map(
            static fn (string $flow): string => (string) json_encode([
                'flow' => $flow,
                'role' => null,
                'outcome' => FlowOutcome::Entered->value,
            ]),
            $flows,
        );

        file_put_contents($this->shots.'/flow-results.jsonl', implode("\n", $lines)."\n");
    }

    private function writeCapture(string $name): void
    {
        file_put_contents($this->shots.'/'.$name.'.png', 'PNG-'.$name);
    }

    /**
     * Build the record-host fixture (capture dir + run manifest + the pinned-Chromium signature) rooted at an
     * ARBITRARY $base, point the command's base_path() + PLAYWRIGHT_BROWSERS_PATH there, and designate the host.
     * Used by the distinct-dir tests whose $base lives on a case-sensitive volume.
     *
     * @param  list<string>  $entered  flow keys the manifest records as ENTERED
     * @param  list<string>  $captures  capture basenames to write under Screenshots
     */
    private function seedRecordHost(string $base, array $entered, array $captures): void
    {
        $shots = $base.'/tests/Browser/Screenshots';
        $browsers = $base.'/.ms-playwright';

        @mkdir($shots, 0775, true);
        @mkdir($browsers.'/chromium-'.self::REV, 0775, true);
        @mkdir($base.'/node_modules/playwright-core', 0775, true);
        file_put_contents(
            $base.'/node_modules/playwright-core/browsers.json',
            json_encode(['browsers' => [['name' => 'chromium', 'revision' => self::REV]]]),
        );
        putenv('PLAYWRIGHT_BROWSERS_PATH='.$browsers);

        $lines = array_map(
            static fn (string $flow): string => (string) json_encode([
                'flow' => $flow,
                'role' => null,
                'outcome' => FlowOutcome::Entered->value,
            ]),
            $entered,
        );
        file_put_contents($shots.'/flow-results.jsonl', implode("\n", $lines)."\n");

        foreach ($captures as $name) {
            file_put_contents($shots.'/'.$name.'.png', 'PNG-'.$name);
        }

        $this->designate(true);
        $this->app->setBasePath($base);
    }

    /**
     * Mount a case-SENSITIVE scratch volume and return an app base on it, so the command's Screenshots (capture)
     * and screenshots (baseline) dirs are DISTINCT — the case this macOS fold volume cannot otherwise produce.
     * Skips the test (rather than passing vacuously) when a case-sensitive volume can't be provisioned.
     */
    private function caseSensitiveBaseOrSkip(): string
    {
        $tmp = sys_get_temp_dir().'/bj17-cs-'.bin2hex(random_bytes(6));
        $this->csImage = $tmp.'.sparseimage';
        $this->csMount = $tmp.'-mnt';
        @mkdir($this->csMount, 0775, true);

        exec('hdiutil create -size 48m -fs '.escapeshellarg('Case-sensitive APFS')
            .' -volname bj17cs -type SPARSE -quiet '.escapeshellarg($this->csImage).' 2>&1', $o1, $r1);
        exec('hdiutil attach -quiet -nobrowse -mountpoint '.escapeshellarg($this->csMount).' '
            .escapeshellarg($this->csImage).' 2>&1', $o2, $r2);

        @mkdir($this->csMount.'/CASEprobe');
        $folds = is_dir($this->csMount.'/caseprobe');
        @rmdir($this->csMount.'/CASEprobe');

        if ($r1 !== 0 || $r2 !== 0 || $folds) {
            $this->detachCaseSensitive();
            $this->markTestSkipped('could not provision a case-sensitive scratch volume (hdiutil unavailable or folding).');
        }

        $base = $this->csMount.'/app';
        @mkdir($base, 0775, true);

        return $base;
    }

    private function detachCaseSensitive(): void
    {
        if ($this->csMount !== null) {
            exec('hdiutil detach -quiet -force '.escapeshellarg($this->csMount).' 2>/dev/null');
            @rmdir($this->csMount);
            $this->csMount = null;
        }

        if ($this->csImage !== null) {
            @unlink($this->csImage);
            $this->csImage = null;
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

/** Two declared flows for the catalog membership + --flow narrowing exercises (both dotted, guest, no routes). */
final class Bj17TestFlows implements DeclaresFlows
{
    public static function flows(): array
    {
        return [
            new FlowDeclaration(
                key: 'readings.offering',
                label: 'Readings offering',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
            new FlowDeclaration(
                key: 'subscribe.door',
                label: 'Subscribe door',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
        ];
    }
}

/**
 * browser-journeys 25: a SECOND package's flows. One exclusive to it (`account.security`) and one
 * (`shared.widget`) also declared by {@see Bj25CommerceFlows} — the co-declared key that must land in
 * BOTH package repos. Declared in the test under `by: 'splicewire/beam-accounts'`.
 */
final class Bj25AccountsFlows implements DeclaresFlows
{
    public static function flows(): array
    {
        return [
            new FlowDeclaration(
                key: 'account.security',
                label: 'Account security',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
            new FlowDeclaration(
                key: 'shared.widget',
                label: 'Shared widget',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
        ];
    }
}

/**
 * browser-journeys 25: a THIRD package's flows. One exclusive (`commerce.cart`) and the same
 * `shared.widget` {@see Bj25AccountsFlows} declares — proving the co-declared key is duplicated into
 * both repos, never tie-broken. Declared in the test under `by: 'splicewire/beam-commerce'`.
 */
final class Bj25CommerceFlows implements DeclaresFlows
{
    public static function flows(): array
    {
        return [
            new FlowDeclaration(
                key: 'commerce.cart',
                label: 'Commerce cart',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
            new FlowDeclaration(
                key: 'shared.widget',
                label: 'Shared widget',
                routes: [],
                gate: AlwaysOn::class,
                checkpoint: null,
                roles: [],
            ),
        ];
    }
}
