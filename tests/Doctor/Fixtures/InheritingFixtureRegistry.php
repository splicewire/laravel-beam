<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

/**
 * The estate's shipped extension shape: a subclass that seeds defaults and declares NOTHING, running
 * under its parent's declaration.
 *
 * ⚠️ **There is no production instance any more, so this fixture is now the ONLY thing pinning the
 * parent-chain walk.** `Splicewire\Tower\Capabilities\CapabilityRegistry` was the live one; it was
 * deleted 2026-08-28 in favour of seeding `beam.capabilities` from `TowerServiceProvider::packageBooted()`,
 * on the argument that a second seeding site does not need a type. Ticket 42's decision is unchanged and
 * still correct — subclassing remains a legal extension shape — but nothing in the estate exercises it
 * today, which is exactly why the fixture must stay: delete it and `IsRegistry::of()` could stop walking
 * without a single test turning red.
 *
 * Before registry-kernel ticket 42 a host binding this presented a live registry object that BOTH
 * conformance audits filtered out of their populations before any check ran — the gate could not fail
 * it and the advisory could not row it.
 */
class InheritingFixtureRegistry extends ConformingFixtureRegistry {}
