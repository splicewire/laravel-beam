<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

/**
 * The estate's shipped extension shape: a subclass that seeds defaults and declares NOTHING, running
 * under its parent's declaration. `Splicewire\Tower\Capabilities\CapabilityRegistry` is the live one.
 *
 * Before registry-kernel ticket 42 a host binding this presented a live registry object that BOTH
 * conformance audits filtered out of their populations before any check ran — the gate could not fail
 * it and the advisory could not row it.
 */
class InheritingFixtureRegistry extends ConformingFixtureRegistry {}
