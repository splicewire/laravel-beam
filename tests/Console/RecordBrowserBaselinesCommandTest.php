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
 * CASE-SENSITIVITY NOTE (documented limitation, not a gap): on the macOS case-insensitive record host,
 * `tests/Browser/Screenshots` (capture) and `tests/Browser/screenshots` (baseline) are the SAME inode,
 * so a capture can never BYTE-DIFFER from its baseline here. The differ-gated branches — staleness
 * layer-3's ABORT (stale AND differs) and `report()`'s changed/new counts — are therefore inert on this
 * host by design (belt-and-suspenders for a case-SENSITIVE CI runner) and are not driveable from here.
 * Every branch reachable on a case-insensitive host is exercised below, including layer-3's stale→skip.
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
