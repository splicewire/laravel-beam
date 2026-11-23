<?php

namespace Splicewire\Beam\Tests\Doctor\Support;

use Splicewire\Beam\Doctor\Support\BrowserCoverageReader;
use Splicewire\Beam\Doctor\Support\BrowserCoverageReport;
use Splicewire\Beam\Tests\TestCase;

/**
 * browser-journeys 13 (ticket 03 Q6): the coverage reader's one job is that an ABSENCE never reads as a
 * pass. A mounted suite the run filtered out, or an unmounted one that could never be collected, leaves zero
 * testcases in the JUnit — and the reader must call that `not-collected` (report NOT ok), distinctly from a
 * suite that actually collected and passed. These tests pin the discriminating reads: proved vs. filtered
 * vs. unmounted vs. failed vs. honestly-skipped, and that a missing JUnit degrades to not-collected, never a
 * throw.
 */
class BrowserCoverageReaderTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/beam-browser-cov-'.bin2hex(random_bytes(6));
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
        parent::tearDown();
    }

    /** Create a vendored package's tests/Browser with $files `*Test.php`; return the realpath of that dir. */
    private function vendorSuite(string $package, int $files = 1): string
    {
        $dir = $this->base.'/vendor/'.$package.'/tests/Browser';
        mkdir($dir, 0777, true);
        for ($i = 1; $i <= $files; $i++) {
            file_put_contents($dir."/Thing{$i}Test.php", "<?php\n");
        }

        return realpath($dir);
    }

    private function phpunit(bool $mount): void
    {
        $dir = $mount
            ? '<directory>vendor/splicewire/*/tests/Browser</directory>'
            : '<directory>tests/Browser</directory>';

        file_put_contents($this->base.'/phpunit.xml',
            '<?xml version="1.0"?><phpunit><testsuites><testsuite name="Browser">'.$dir.'</testsuite></testsuites></phpunit>');
    }

    /**
     * Write a JUnit file from a list of [absolute file path, outcome] pairs. outcome: passed|failed|skipped.
     *
     * @param  list<array{0: string, 1: string}>  $cases
     */
    private function junit(array $cases): string
    {
        $xml = '<?xml version="1.0"?><testsuites><testsuite name="Browser">';
        foreach ($cases as [$file, $outcome]) {
            $attr = 'name="t" file="'.htmlspecialchars($file, ENT_QUOTES).'"';
            $xml .= match ($outcome) {
                'failed' => '<testcase '.$attr.'><failure>boom</failure></testcase>',
                'skipped' => '<testcase '.$attr.'><skipped message="off in CI"/></testcase>',
                default => '<testcase '.$attr.'/>',
            };
        }
        $xml .= '</testsuite></testsuites>';

        $path = $this->base.'/junit.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    private function read(string $junitPath): BrowserCoverageReport
    {
        return (new BrowserCoverageReader(null, $this->base))->read($junitPath);
    }

    /** @param  list<string>  $only */
    private function readOnly(string $junitPath, array $only): BrowserCoverageReport
    {
        return (new BrowserCoverageReader(null, $this->base))->read($junitPath, $only);
    }

    public function test_a_collected_passing_suite_is_proved(): void
    {
        $dir = $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: true);
        $junit = $this->junit([[$dir.'/Thing1Test.php', 'passed']]);

        $report = $this->read($junit);

        $this->assertTrue($report->ok());
        $this->assertSame(BrowserCoverageReport::PROVED, $report->items[0]['status']);
    }

    public function test_a_filtered_out_mounted_suite_is_not_collected_not_proved(): void
    {
        // The discriminating case: the suite is mounted and ships tests, but the run collected NONE of it
        // (a --filter narrowed it out). A naive reader sees an all-green JUnit; this one must refuse it.
        $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: true);
        $junit = $this->junit([]); // a green run that happened to collect nothing from this package

        $report = $this->read($junit);

        $this->assertFalse($report->ok());
        $this->assertSame(BrowserCoverageReport::NOT_COLLECTED, $report->items[0]['status']);
        $this->assertSame(0, $report->items[0]['testcases']);
        // The probable cause names the filter path, distinctly from the unmounted cause below.
        $this->assertStringContainsString('mounted but the run collected none', $report->items[0]['detail']);
    }

    public function test_an_unmounted_suite_is_not_collected_with_the_unmounted_cause(): void
    {
        $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: false); // ships tests, but this host never mounts them
        $junit = $this->junit([]);

        $report = $this->read($junit);

        $this->assertFalse($report->ok());
        $this->assertSame(BrowserCoverageReport::NOT_COLLECTED, $report->items[0]['status']);
        $this->assertStringContainsString('not mounted', $report->items[0]['detail']);
    }

    public function test_a_failing_testcase_makes_the_suite_failed(): void
    {
        $dir = $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: true);
        $junit = $this->junit([
            [$dir.'/Thing1Test.php', 'passed'],
            [$dir.'/Thing1Test.php', 'failed'],
        ]);

        $report = $this->read($junit);

        $this->assertFalse($report->ok());
        $this->assertSame(BrowserCoverageReport::FAILED, $report->items[0]['status']);
    }

    public function test_an_all_skipped_suite_is_collected_and_honestly_off_not_a_failure(): void
    {
        // Skipped is NOT not-collected: the suite WAS collected and reported itself off. That is honest, so
        // the report stays ok — the reader only fails absence and real failure, never a declared skip.
        $dir = $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: true);
        $junit = $this->junit([[$dir.'/Thing1Test.php', 'skipped']]);

        $report = $this->read($junit);

        $this->assertTrue($report->ok());
        $this->assertSame(BrowserCoverageReport::SKIPPED, $report->items[0]['status']);
        $this->assertStringContainsString('off in CI', $report->items[0]['detail']);
    }

    public function test_a_missing_junit_degrades_to_not_collected_and_never_throws(): void
    {
        $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->phpunit(mount: true);

        $report = $this->read($this->base.'/does-not-exist.xml');

        $this->assertFalse($report->ok());
        $this->assertSame(BrowserCoverageReport::NOT_COLLECTED, $report->items[0]['status']);
    }

    public function test_a_package_with_an_empty_browser_dir_is_not_an_expected_suite(): void
    {
        // tests/Browser exists but ships no *Test.php — the mount audit answers for emptiness, not the reader.
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 0);
        $this->phpunit(mount: true);

        $report = $this->read($this->junit([]));

        $this->assertSame([], $report->items);
        $this->assertTrue($report->ok()); // nothing expected, nothing missing
    }

    public function test_two_packages_are_attributed_by_file_not_name(): void
    {
        // A testcase whose file lives under accounts must NOT count toward teams (F13: attribute by file).
        $accounts = $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->vendorSuite('splicewire/laravel-beam-teams');
        $this->phpunit(mount: true);
        $junit = $this->junit([[$accounts.'/Thing1Test.php', 'passed']]);

        $report = $this->read($junit);

        $byPackage = [];
        foreach ($report->items as $item) {
            $byPackage[$item['package']] = $item['status'];
        }

        $this->assertSame(BrowserCoverageReport::PROVED, $byPackage['splicewire/laravel-beam-accounts']);
        $this->assertSame(BrowserCoverageReport::NOT_COLLECTED, $byPackage['splicewire/laravel-beam-teams']);
        $this->assertFalse($report->ok());
    }

    public function test_only_scopes_the_expected_set_to_the_named_target(): void
    {
        // browser-journeys 12: a per-package ref run passes --only <target>. A sibling the starter merely
        // vendors (teams) must NOT be demanded here — it is validated by its own ref run. With the target
        // proved and the sibling excluded from the expected set, the report is ok.
        $accounts = $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->vendorSuite('splicewire/laravel-beam-teams'); // a vendored sibling, collected by no run here
        $this->phpunit(mount: true);
        $junit = $this->junit([[$accounts.'/Thing1Test.php', 'passed']]);

        $report = $this->readOnly($junit, ['splicewire/laravel-beam-accounts']);

        $this->assertTrue($report->ok());
        $this->assertCount(1, $report->items);
        $this->assertSame('splicewire/laravel-beam-accounts', $report->items[0]['package']);
        $this->assertSame(BrowserCoverageReport::PROVED, $report->items[0]['status']);
    }

    public function test_only_does_not_excuse_the_targets_own_absence(): void
    {
        // Scoping narrows WHICH suites are expected; it never turns the named target's OWN zero-testcases
        // into a pass. The target is mounted and ships tests but the run collected none of it → still
        // not-collected, report NOT ok — the core invariant holds inside the scoped set too.
        $this->vendorSuite('splicewire/laravel-beam-accounts');
        $this->vendorSuite('splicewire/laravel-beam-teams');
        $this->phpunit(mount: true);
        $junit = $this->junit([]); // a green run that collected nothing

        $report = $this->readOnly($junit, ['splicewire/laravel-beam-accounts']);

        $this->assertFalse($report->ok());
        $this->assertCount(1, $report->items);
        $this->assertSame('splicewire/laravel-beam-accounts', $report->items[0]['package']);
        $this->assertSame(BrowserCoverageReport::NOT_COLLECTED, $report->items[0]['status']);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
