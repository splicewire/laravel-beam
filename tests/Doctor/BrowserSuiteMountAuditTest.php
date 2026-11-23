<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BrowserSuiteMountAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * browser-journeys 13 (ticket 03 Q2/Q3): the static mount audit tells four look-alike states apart, each
 * with a distinct repair — a suite that is mounted-and-proving, one installed but UNMOUNTED (a real run
 * gap, FAIL), one mounted but EMPTY (a hollow suite, FAIL), and one simply ABSENT from the install (ticket
 * 12 not landed yet, a NOTE not a failure). And a host that cannot run browser tests at all reports one
 * inconclusive NOTE and never throws (F12) — the discriminating-tests done-when.
 */
class BrowserSuiteMountAuditTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/beam-browser-mount-'.bin2hex(random_bytes(6));
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
        parent::tearDown();
    }

    /** A runnable host: Pest 4 + the browser plugin declared. */
    private function runnableComposer(): void
    {
        file_put_contents($this->base.'/composer.json', json_encode([
            'require-dev' => [
                'pestphp/pest' => '^4.0',
                'pestphp/pest-plugin-browser' => '^4.0',
            ],
        ]));
    }

    /** Mount the sanctioned installed-package glob (or, when $mount is false, mount only the host's own dir). */
    private function phpunit(bool $mount): void
    {
        $dir = $mount
            ? '<directory>vendor/splicewire/*/tests/Browser</directory>'
            : '<directory>tests/Browser</directory>';

        file_put_contents($this->base.'/phpunit.xml', <<<XML
            <?xml version="1.0"?>
            <phpunit>
                <testsuites>
                    <testsuite name="Browser">{$dir}</testsuite>
                </testsuites>
            </phpunit>
            XML);
    }

    /** Create a vendored package's tests/Browser, with $files `*Test.php` files in it. */
    private function vendorSuite(string $package, int $files): void
    {
        $dir = $this->base.'/vendor/'.$package.'/tests/Browser';
        mkdir($dir, 0777, true);
        for ($i = 1; $i <= $files; $i++) {
            file_put_contents($dir."/Thing{$i}Test.php", "<?php\n");
        }
    }

    /** @return list<\Rushing\Doctor\Finding> */
    private function audit(): array
    {
        return (new BrowserSuiteMountAudit(null, $this->base))->run();
    }

    private function tags(array $findings): array
    {
        return array_map(static fn ($f): string => $f->detail, $findings);
    }

    public function test_a_mounted_nonempty_suite_passes(): void
    {
        $this->runnableComposer();
        $this->phpunit(mount: true);
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 2);

        $findings = $this->audit();

        $this->assertNotContainsFail($findings);
        $this->assertDetailContains($findings, BrowserSuiteMountAudit::TAG_MOUNTED);
        $this->assertDetailContains($findings, 'splicewire/laravel-beam-accounts');
    }

    public function test_an_installed_but_unmounted_suite_fails_distinctly(): void
    {
        $this->runnableComposer();
        $this->phpunit(mount: false); // mounts only tests/Browser — the vendored suite is NOT collected
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 3);

        $findings = $this->audit();

        $fail = $this->onlyFail($findings);
        $this->assertStringContainsString(BrowserSuiteMountAudit::TAG_UNMOUNTED, $fail->detail);
        $this->assertStringContainsString('3 browser test files', $fail->detail);
    }

    public function test_a_mounted_but_empty_suite_fails_distinctly(): void
    {
        $this->runnableComposer();
        $this->phpunit(mount: true);
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 0); // dir exists, no *Test.php

        $findings = $this->audit();

        $fail = $this->onlyFail($findings);
        $this->assertStringContainsString(BrowserSuiteMountAudit::TAG_EMPTY, $fail->detail);
    }

    public function test_no_installed_suite_is_an_absent_note_not_a_failure(): void
    {
        $this->runnableComposer();
        $this->phpunit(mount: true); // mount glob declared, but nothing installed ships tests/Browser

        $findings = $this->audit();

        $this->assertNotContainsFail($findings);
        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString(BrowserSuiteMountAudit::TAG_ABSENT, $findings[0]->detail);
        // It distinctly says the host IS wired for a suite (mount glob present), ticket 12 just hasn't landed.
        $this->assertStringContainsString('the mount glob IS declared', $findings[0]->detail);
    }

    public function test_a_host_that_cannot_run_browser_tests_is_an_inconclusive_note_and_never_fails(): void
    {
        // Pest 4 but NO browser plugin: the suite is unmounted, yet this host could never have run it.
        file_put_contents($this->base.'/composer.json', json_encode([
            'require-dev' => ['pestphp/pest' => '^4.0'],
        ]));
        $this->phpunit(mount: false);
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 2);

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertNotContainsFail($findings); // never failed for an unmounted suite it could not run
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString(BrowserSuiteMountAudit::TAG_UNRUNNABLE, $findings[0]->detail);
    }

    public function test_pest_three_host_is_unrunnable_not_failed(): void
    {
        file_put_contents($this->base.'/composer.json', json_encode([
            'require-dev' => ['pestphp/pest' => '^3.0', 'pestphp/pest-plugin-browser' => '^2.0'],
        ]));
        $this->phpunit(mount: false);
        $this->vendorSuite('splicewire/laravel-beam-accounts', files: 2);

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString(BrowserSuiteMountAudit::TAG_UNRUNNABLE, $findings[0]->detail);
    }

    public function test_it_never_throws_on_an_empty_host(): void
    {
        // No composer.json, no phpunit.xml, no vendor/ — a bare host must answer, not throw.
        $findings = $this->audit();

        $this->assertNotEmpty($findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
    }

    /* ---------------- helpers ---------------- */

    private function onlyFail(array $findings): \Rushing\Doctor\Finding
    {
        $fails = array_values(array_filter($findings, static fn ($f): bool => $f->status === DoctorStatus::Fail));
        $this->assertCount(1, $fails, 'expected exactly one Fail among: '.implode(' | ', $this->tags($findings)));

        return $fails[0];
    }

    private function assertNotContainsFail(array $findings): void
    {
        foreach ($findings as $f) {
            $this->assertNotSame(DoctorStatus::Fail, $f->status, 'unexpected Fail: '.$f->detail);
        }
    }

    private function assertDetailContains(array $findings, string $needle): void
    {
        $this->assertStringContainsString($needle, implode(' | ', $this->tags($findings)));
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
