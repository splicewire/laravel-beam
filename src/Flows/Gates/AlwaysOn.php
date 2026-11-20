<?php

namespace Splicewire\Beam\Flows\Gates;

use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;

/**
 * The gate a flow names when it has no runtime switch: it is always {@see FlowState::On} (browser-journeys
 * 02, R3-Q1). Surfaces and any host-rendered flow (e.g. commerce billing) declare this explicitly — the
 * declaration has no gate default, so "no switch" is a written decision, not an omission.
 */
final class AlwaysOn implements FlowGate
{
    public function state(): FlowState
    {
        return FlowState::On;
    }
}
