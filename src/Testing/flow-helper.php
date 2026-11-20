<?php

namespace Splicewire\Beam\Testing;

use Pest\TestSuite;
use PHPUnit\Framework\TestCase;

if (! function_exists('Splicewire\Beam\Testing\flow')) {
    /**
     * Name the flow a shipped browser test proves, through one helper (browser-journeys 10). Called as the
     * first line of the test body, it:
     *  - validates the key grammar and the role against the `beam.flows` declaration;
     *  - requires the host test case to carry one of Laravel's four reset traits;
     *  - writes one {@see FlowResultData} row to the run's gitignored `flow-results.jsonl`;
     *  - SKIPS the test with the covering `BEAM.md` Deviation's reason when one applies;
     *  - returns the {@see FlowScreenshot} whose `->name()` the test hands to `screenshot(filename:)`.
     *
     * `$width` is the fixed viewport width the determinism scheme pins, appended to the screenshot name so a
     * flow's baselines at two widths never collide.
     *
     * (This global lives in a file named to avoid a case-insensitive-filesystem clash with the {@see Flow}
     * class — macOS treats `Flow.php` and `flow.php` as one path.)
     */
    function flow(string $key, ?string $role = null, int $width = 1280): FlowScreenshot
    {
        $test = TestSuite::getInstance()->test;

        assert($test instanceof TestCase);

        return FlowRun::record($test, $key, $role, $width);
    }
}
