<?php

namespace Splicewire\Beam\Testing;

/**
 * The browser-test helper's own outcome for a flow in one test (browser-journeys 10, ticket 02 ruling 8),
 * written into {@see FlowResultData}. This is the HELPER's verdict, not the test's pass/fail — the Sweep
 * joins pass/fail from JUnit on (file, test). A flow is either entered (the test ran its body) or skipped
 * because a `BEAM.md` Deviation covers it.
 */
enum FlowOutcome: string
{
    case Entered = 'entered';
    case SkippedDeviation = 'skipped-deviation';
}
