<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 149 — the shared expansion of `vendor/<vendor>` into one resolved root per package.
 *
 * ## Why the symlink case is the whole test
 *
 * The defect this class exists to end is invisible to any test built over a tree of real directories:
 * `RecursiveDirectoryIterator` walks those perfectly well. It only fails on the shape a co-dev host actually
 * has — `vendor/<vendor>/<package>` as a **symlink** to a working checkout — which is why
 * {@see test_it_resolves_a_symlinked_package_to_its_target()} builds one rather than trusting the audits'
 * own fixtures. Three audits carried this bug and every one of their suites was green.
 *
 * The parity test below is the other half: it asserts the copies are gone, so the next audit to need scan
 * roots cannot quietly re-derive them. A docblock already tried that and a third audit copied the defect
 * out of it.
 */
class HostScanRootsTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /** A fresh base path, keyed per-run: the estate's fixed-scratch-dir collision has cost a suite before. */
    private function makeRoot(): string
    {
        $this->root = sys_get_temp_dir().'/beam-scan-roots-'.getmypid().'-'.bin2hex(random_bytes(6));

        mkdir($this->root, 0777, true);
        $this->app->setBasePath($this->root);

        return $this->root;
    }

    public function test_it_resolves_a_symlinked_package_to_its_target(): void
    {
        $root = $this->makeRoot();

        mkdir($root.'/app', 0777, true);
        mkdir($root.'/vendor/splicewire', 0777, true);
        mkdir($checkout = $root.'/checkouts/laravel-beam-ux/src', 0777, true);

        symlink(dirname($checkout), $root.'/vendor/splicewire/laravel-beam-ux');

        $roots = HostScanRoots::resolve();

        // The link is followed and `<pkg>/src` preferred — the package root itself must NOT appear, because
        // each family package carries its own dev `vendor/` tree and scanning from one level up re-walks
        // the estate once per package.
        $this->assertContains((string) realpath($checkout), $roots);
        $this->assertNotContains((string) realpath(dirname($checkout)), $roots);
        $this->assertContains((string) realpath($root.'/app'), $roots);
    }

    public function test_a_package_without_a_src_dir_falls_back_to_the_package_root(): void
    {
        $root = $this->makeRoot();

        mkdir($root.'/vendor/rushing', 0777, true);
        mkdir($flat = $root.'/checkouts/flat-package', 0777, true);

        symlink($flat, $root.'/vendor/rushing/flat-package');

        $this->assertContains((string) realpath($flat), HostScanRoots::resolve());
    }

    public function test_a_vendor_entry_that_resolves_nowhere_is_dropped_rather_than_passed_through(): void
    {
        $root = $this->makeRoot();

        mkdir($root.'/vendor/schemastud', 0777, true);
        symlink($root.'/gone', $root.'/vendor/schemastud/retired-package');

        // beam-facade 44's orphaned symlinks into the retired `~/Workspaces/laravel/packages/` layout are a
        // live estate state, in no lock and no `installed.json`. Reporting them is DanglingPathRepoAudit's
        // job; an audit handed one as a scan root would just walk nothing.
        $this->assertSame([], HostScanRoots::resolve([]));
    }

    public function test_the_audits_share_one_expansion_rather_than_three_copies(): void
    {
        $root = $this->makeRoot();

        mkdir($root.'/app', 0777, true);
        mkdir($root.'/routes', 0777, true);
        mkdir($root.'/vendor/splicewire', 0777, true);
        mkdir($pkg = $root.'/checkouts/laravel-beam-accounts/src', 0777, true);

        symlink(dirname($pkg), $root.'/vendor/splicewire/laravel-beam-accounts');

        $this->assertSame(
            HostScanRoots::resolve(),
            $this->rootsOf(CentralPinJustificationAudit::forApp()),
        );

        // The Inertia leg differs only by `routes/`, whose closure renders no other consumer scans.
        $this->assertSame(
            HostScanRoots::resolve(['app', 'routes', 'src']),
            $this->rootsOf(InertiaPropShapeAudit::forApp()),
        );
    }

    /** @return list<string> */
    private function rootsOf(object $audit): array
    {
        $property = new \ReflectionProperty($audit, 'roots');
        $property->setAccessible(true);

        return $property->getValue($audit);
    }
}
