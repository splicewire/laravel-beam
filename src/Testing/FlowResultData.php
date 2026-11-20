<?php

namespace Splicewire\Beam\Testing;

use Spatie\LaravelData\Data;

/**
 * One browser test's flow result, appended as one JSON line to a run-scoped, gitignored
 * `flow-results.jsonl` beside the screenshots (browser-journeys 10; ticket 02 ruling 8).
 *
 * It is a {@see Data} shape because it CROSSES THE PROCESS as a file the Sweep parses (ticket 02 R1-Q1):
 * PHPUnit's JUnit logger carries no `->group()` tag (ticket 02 F13), so the flow key cannot reach the
 * result file through JUnit. The helper writes it here instead, and the Sweep joins each row to the JUnit
 * `testcase` on (`file`, `test`) for the pass/fail the helper does not own.
 *
 * `test` is the PHPUnit test name INCLUDING its dataset suffix (e.g. `… with data set "owner"`), the exact
 * string the JUnit `testcase name` attribute carries (the join key, ticket 02 U1 — measured by 10's proof).
 */
final class FlowResultData extends Data
{
    public function __construct(
        public string $flow,
        public ?string $role,
        public string $package,
        public string $file,
        public string $test,
        public FlowOutcome $outcome,
        public ?string $reason,
    ) {}
}
