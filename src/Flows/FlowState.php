<?php

namespace Splicewire\Beam\Flows;

/**
 * A {@see FlowGate}'s answer for the host, now (browser-journeys 02, R1-Q4): whether a flow is
 * switched On, switched Off, or structurally Absent from this host.
 *
 * `Absent` is the third, structural answer a boolean cannot give: at a host that has declared a whole
 * capability estate absent (beam-accounts' three-state config) or that has not wired Fortify, the flow
 * is neither on nor off — it does not exist to switch. The doctor reads `Absent` as structural
 * applicability (no test expected), `Off` as a Deviation candidate, and `On` as a flow a test must
 * prove.
 */
enum FlowState: string
{
    case On = 'on';
    case Off = 'off';
    case Absent = 'absent';
}
