<?php

namespace Splicewire\Beam\Doctor\Support;

use DOMDocument;
use DOMElement;
use Throwable;

/**
 * browser-journeys 13 (ticket 03 Q6): the read-only, after-a-run half of the mount mechanism. Given the
 * JUnit file a Pest browser run wrote (`--log-junit`), it attributes every `<testcase>` to the installed
 * `splicewire/*` package that owns its `file`, and renders a {@see BrowserCoverageReport} that marks each
 * EXPECTED suite proved / failed / skipped / not-collected.
 *
 * ## It cannot turn an absence into a pass
 *
 * The one thing it exists to prevent: a suite that produced ZERO testcases being read as clean. A mounted
 * package whose tests a `--filter` narrowed out, or whose suite the run never reached, leaves no testcase in
 * the JUnit — and a naive reader that only inspects the testcases that ARE present would see nothing wrong.
 * So the reader does not start from the JUnit; it starts from the {@see BrowserSuiteInventory}'s list of
 * packages that SHIP a browser suite, and for each asks the JUnit "did you collect this one?". Zero
 * testcases is `not-collected`, and {@see BrowserCoverageReport::ok()} is false — never upgraded to proved,
 * whatever the cause.
 *
 * ## Attribution is by the `file` attribute, not the name (F13)
 *
 * PHPUnit's JUnit writes each testcase's absolute source `file`. The reader matches that path against each
 * package's `vendor/splicewire/<pkg>/tests/Browser` directory, so a test is attributed to the package that
 * actually ships it — not guessed from a class or method name that a host could collide with. It names a
 * PROBABLE cause for a not-collected suite from the static inventory (unmounted vs. mounted-but-not-run),
 * but the verdict stands on the JUnit alone; it never needs to see the filter that caused the absence.
 *
 * ## Read-only, and it never throws
 *
 * It opens one XML file and reads it. An unreadable or unparseable JUnit, or a missing one, yields a report
 * where every expected suite is `not-collected` — the honest answer ("this run proved nothing") rather than
 * a crash, so the Sweep still exits non-zero instead of erroring out.
 */
final class BrowserCoverageReader
{
    private readonly BrowserSuiteInventory $inventory;

    public function __construct(?BrowserSuiteInventory $inventory = null, ?string $basePath = null)
    {
        $this->inventory = $inventory ?? new BrowserSuiteInventory($basePath);
    }

    /**
     * Read one JUnit file and render the coverage verdict for every installed browser suite that ships tests.
     *
     * When `$only` is non-empty, the EXPECTED set is scoped to just those package names — the opt-in a
     * per-package reference run passes (browser-journeys 12) so a commerce ref gates on the commerce suite
     * alone, and a sibling the starter merely vendors (accounts, validated by its OWN ref run against a host
     * that mounts its routes) is not demanded here. The DEFAULT (empty `$only`) is unchanged: every installed
     * `splicewire/*` browser suite is expected, so a standalone host sweep still cannot let an unmounted or
     * filtered-out suite read as clean. Scoping narrows WHICH suites are expected; it never excuses a named
     * target's own zero-testcases absence (that stays `not-collected`).
     *
     * @param  list<string>  $only  Package names (e.g. `splicewire/laravel-beam-commerce`) to scope the gate to; `[]` = every installed suite.
     */
    public function read(string $junitPath, array $only = []): BrowserCoverageReport
    {
        $testcases = $this->testcases($junitPath);

        $items = [];
        foreach ($this->inventory->packages() as $package) {
            // Opt-in target scope (browser-journeys 12): when a caller named the package(s) it is verifying,
            // only those are "expected"; the full-inventory default (empty `$only`) is left untouched.
            if ($only !== [] && ! in_array($package['package'], $only, true)) {
                continue;
            }

            // A package with no test files ships no browser suite to prove — the mount audit answers for an
            // empty directory. Only packages that actually carry tests are "expected" here.
            if ($package['testFileCount'] === 0) {
                continue;
            }

            $own = array_values(array_filter(
                $testcases,
                static fn (array $tc): bool => $tc['file'] !== '' && str_contains($tc['file'], $package['path']),
            ));

            $items[] = $this->classify($package, $own);
        }

        return new BrowserCoverageReport($items);
    }

    /**
     * Classify one expected suite from the testcases attributed to it.
     *
     * @param  array{package: string, path: string, testFileCount: int, mounted: bool}  $package
     * @param  list<array{file: string, status: string, reason: string}>  $own
     * @return array{package: string, status: string, testcases: int, detail: string}
     */
    private function classify(array $package, array $own): array
    {
        $name = $package['package'];

        if ($own === []) {
            // The whole point: zero testcases is NEVER proved. Name a probable cause from the static inventory,
            // but the verdict stands on the JUnit regardless of why.
            $cause = $package['mounted']
                ? 'the suite is mounted but the run collected none of it — a `--filter`/`--group` narrowed it out, or the run never reached it'
                : 'the suite is not mounted by this host\'s phpunit.xml (browser-suite-unmounted) — it cannot be collected until it is';

            return [
                'package' => $name,
                'status' => BrowserCoverageReport::NOT_COLLECTED,
                'testcases' => 0,
                'detail' => sprintf('ships %d test file(s) under tests/Browser, but 0 testcases appeared in the JUnit — %s', $package['testFileCount'], $cause),
            ];
        }

        $failed = array_values(array_filter($own, static fn (array $tc): bool => $tc['status'] === 'failed'));
        if ($failed !== []) {
            return [
                'package' => $name,
                'status' => BrowserCoverageReport::FAILED,
                'testcases' => count($own),
                'detail' => sprintf('%d of %d collected testcase(s) failed or errored', count($failed), count($own)),
            ];
        }

        $skipped = array_values(array_filter($own, static fn (array $tc): bool => $tc['status'] === 'skipped'));
        if (count($skipped) === count($own)) {
            $reason = $skipped[0]['reason'] ?? '';

            return [
                'package' => $name,
                'status' => BrowserCoverageReport::SKIPPED,
                'testcases' => count($own),
                'detail' => sprintf('all %d collected testcase(s) skipped%s', count($own), $reason === '' ? '' : ' ('.$reason.')'),
            ];
        }

        return [
            'package' => $name,
            'status' => BrowserCoverageReport::PROVED,
            'testcases' => count($own),
            'detail' => sprintf('%d testcase(s) collected and passed%s', count($own) - count($skipped), $skipped === [] ? '' : sprintf(', %d skipped', count($skipped))),
        ];
    }

    /**
     * Every `<testcase>` in the JUnit, as `{file, status, reason}` where status is passed|failed|skipped.
     * A missing/unreadable/unparseable file yields `[]` — so every expected suite reads as not-collected.
     *
     * @return list<array{file: string, status: string, reason: string}>
     */
    private function testcases(string $junitPath): array
    {
        if (! is_file($junitPath)) {
            return [];
        }

        try {
            $xml = (string) @file_get_contents($junitPath);
            if ($xml === '') {
                return [];
            }

            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument;
            $loaded = $dom->loadXML($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded === false) {
                return [];
            }

            $out = [];
            foreach ($dom->getElementsByTagName('testcase') as $case) {
                if (! $case instanceof DOMElement) {
                    continue;
                }

                [$status, $reason] = $this->statusOf($case);
                $out[] = [
                    'file' => (string) $case->getAttribute('file'),
                    'status' => $status,
                    'reason' => $reason,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A testcase's outcome from its children: a `<failure>` or `<error>` is failed, a `<skipped>` is skipped
     * (carrying its message), otherwise passed.
     *
     * @return array{0: string, 1: string}
     */
    private function statusOf(DOMElement $case): array
    {
        foreach (['failure', 'error'] as $bad) {
            if ($case->getElementsByTagName($bad)->length > 0) {
                return ['failed', ''];
            }
        }

        $skipped = $case->getElementsByTagName('skipped');
        if ($skipped->length > 0) {
            $reason = '';
            $node = $skipped->item(0);
            if ($node instanceof DOMElement) {
                $reason = trim($node->getAttribute('message')) ?: trim($node->textContent);
            }

            return ['skipped', $reason];
        }

        return ['passed', ''];
    }
}
