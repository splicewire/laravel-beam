<?php

namespace Splicewire\Beam\Flows;

/**
 * A flow's switch, answered for the host and only the host (browser-journeys 02, R3-Q1). It is
 * resolved from the container, so a gate may depend on the package's own services (doors, config,
 * Fortify features), and it reads the SAME switch the package's runtime code reads (PRD story 22).
 *
 * It takes no actor and no role: every live switch is host-level, and the doctor runs from artisan
 * where there is no actor. Who may SEE a flow is the role axis on the declaration, not the gate.
 *
 * It MUST NOT throw. The doctor catches a throw and reports it as a "gate threw" finding rather than
 * letting it propagate. A flow with no runtime switch names {@see Gates\AlwaysOn} explicitly.
 */
interface FlowGate
{
    public function state(): FlowState;
}
