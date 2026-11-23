<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\BrowserSuiteInventory;

/**
 * browser-journeys 13 (ticket 03 Q2/Q3): the installed-package browser-suite mount is CORRECT — every
 * `splicewire/*` package that ships a `tests/Browser` suite is collected by this host's phpunit.xml, and
 * no mounted directory is empty. The static half of the mechanism; {@see Support\BrowserCoverageReader} is
 * the after-a-run half that proves the mounted suites actually executed.
 *
 * ## Why this gates, and what it refuses to confuse
 *
 * A host that vendors a package's browser suite but does NOT mount it reports a green test run while
 * executing none of that suite — the exact silent-success this audit exists to catch, so an unmounted or
 * empty suite FAILs ({@see BeamServiceProvider} registers it `gate: true`). But it is careful to separate
 * three states that look alike and repair differently:
 *
 *   - `browser-suite-unmounted` — the package ships `*Test.php` files under `tests/Browser`, and no
 *     testsuite `<directory>` in phpunit.xml collects that path. A real mount gap: add the
 *     `vendor/splicewire/<pkg>/tests/Browser` glob (ticket 03's one mount).
 *   - `browser-suite-empty` — the package's `tests/Browser` IS collected, but holds no `*Test.php`, so the
 *     suite contributes zero tests. A mounted hollow suite is as silent as an unmounted one.
 *   - `browser-suite-absent` — the mount glob is declared but no installed `splicewire/*` package ships a
 *     `tests/Browser` at all (ticket 12 has not landed the package suites yet). Nothing to collect and
 *     nothing broken: a NOTE, never a failure (browser-journeys 13 does not block on 12).
 *
 * ## It never throws, and it NOTEs the host that cannot run
 *
 * Registered `gate: true` and run from artisan on a partially-composed host, so like {@see FlowDoctorAudit}
 * every read degrades rather than propagates (the inventory reads files only — it boots no Pest class, so a
 * missing browser plugin cannot throw here, F12). A host on Pest < 4 or without `pest-plugin-browser` CANNOT
 * run browser tests at all; mounting is moot there, so the audit reports one inconclusive
 * `browser-suite-unrunnable` NOTE naming that and stops, rather than failing a host for an unmounted suite it
 * could never have run ({@see Finding::inconclusive()} — status Pass, conclusive false).
 */
class BrowserSuiteMountAudit implements DoctorAudit
{
    public const CHECK = 'installed browser suites mounted';

    public const TAG_UNMOUNTED = 'browser-suite-unmounted';

    public const TAG_EMPTY = 'browser-suite-empty';

    public const TAG_UNRUNNABLE = 'browser-suite-unrunnable';

    public const TAG_ABSENT = 'browser-suite-absent';

    public const TAG_MOUNTED = 'browser-suite-mounted';

    private readonly BrowserSuiteInventory $inventory;

    public function __construct(?BrowserSuiteInventory $inventory = null, ?string $basePath = null)
    {
        $this->inventory = $inventory ?? new BrowserSuiteInventory($basePath);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        // A host that cannot run Pest browser tests at all (no Pest 4, or no browser plugin) cannot be held
        // to a mount: there is nothing to collect INTO. Report it as a NOTE and stop — never fail such a host
        // for an unmounted suite it could not have executed. (The inventory reads files only, so this branch
        // also covers the plugin-less host that would otherwise throw at boot — F12 — without booting it.)
        if (($this->inventory->pestMajor() ?? 0) < 4 || ! $this->inventory->hasBrowserPlugin()) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                '[%s] this host cannot run Pest browser suites (needs pestphp/pest ^4 and pestphp/pest-plugin-browser), '.
                'so the installed-package mount cannot be measured here. No action for browser-journeys 13.',
                self::TAG_UNRUNNABLE,
            ))];
        }

        $packages = $this->inventory->packages();

        if ($packages === []) {
            // Can run, but no installed splicewire/* package ships a tests/Browser suite. If the host declares
            // the mount glob, say so distinctly (the mount is wired, the suites are simply not installed yet —
            // ticket 12); otherwise it is simply nothing to measure.
            return [Finding::inconclusive(self::CHECK, sprintf(
                '[%s] no installed `splicewire/*` package ships a `tests/Browser` suite%s — nothing to mount yet. '.
                'When a capability package lands its browser suite, the `%s` mount collects it with no host edit.',
                self::TAG_ABSENT,
                $this->inventory->mountPatternDeclared()
                    ? ' (the mount glob IS declared in phpunit.xml, so the host is ready for one)'
                    : '',
                BrowserSuiteInventory::MOUNT_GLOB,
            ))];
        }

        $findings = [];
        $mounted = [];

        foreach ($packages as $package) {
            $name = $package['package'];
            $count = $package['testFileCount'];

            if ($package['mounted']) {
                if ($count === 0) {
                    $findings[] = Finding::fail(self::CHECK, sprintf(
                        '[%s] `%s` has a `tests/Browser` directory collected by this host, but it holds no `*Test.php` '.
                        'files — a mounted-but-hollow suite runs green while proving nothing. Remove the empty directory '.
                        'or add its browser tests.',
                        self::TAG_EMPTY,
                        $name,
                    ));
                } else {
                    $mounted[] = $name;
                }

                continue;
            }

            if ($count > 0) {
                $findings[] = Finding::fail(self::CHECK, sprintf(
                    '[%s] `%s` ships %d browser test file%s under `tests/Browser`, but no phpunit.xml testsuite collects '.
                    'that path — the suite is installed yet never runs. Mount it with the `%s` glob (browser-journeys 13).',
                    self::TAG_UNMOUNTED,
                    $name,
                    $count,
                    $count === 1 ? '' : 's',
                    BrowserSuiteInventory::MOUNT_GLOB,
                ));
            }
            // A dir present, empty, AND not mounted is neither a run gap nor a hollow mount: nothing to report.
        }

        if ($mounted !== []) {
            $findings[] = Finding::pass(self::CHECK, sprintf(
                '[%s] %d installed browser suite%s mounted and non-empty: %s.',
                self::TAG_MOUNTED,
                count($mounted),
                count($mounted) === 1 ? '' : 's',
                implode(', ', $mounted),
            ));
        }

        if ($findings === []) {
            // Packages present but each empty-and-unmounted: nothing mounted, nothing broken.
            return [Finding::inconclusive(self::CHECK, sprintf(
                '[%s] installed `splicewire/*` packages carry no non-empty `tests/Browser` suite to mount yet.',
                self::TAG_ABSENT,
            ))];
        }

        return $findings;
    }
}
