<?php

namespace Splicewire\Beam\Tests\Fixtures;

use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Tests\CentralConnectionAliasTest;

/**
 * Exposes `registerCentralConnectionAlias()` so its guard branches can be driven against a config
 * state the test controls.
 *
 * Needed because of a Testbench ordering artifact, NOT a property of the code under test: the real
 * `register()` phase reads a fully-loaded config (Laravel loads every config file before the first
 * provider registers), whereas Testbench applies `defineEnvironment()` AFTER package providers have
 * registered — so the wired alias in a Testbench app is always a copy of Testbench's own default
 * `testing` block, and a test that sets `database.*` in `defineEnvironment` can never observe the
 * guard it thinks it is exercising. {@see CentralConnectionAliasTest}
 * asserts the wiring itself; this probe asserts the rules.
 *
 * Moved here from beam-accounts with the alias itself (beam-facade ticket 96).
 */
class AliasProbeProvider extends BeamServiceProvider
{
    public function probeCentralConnectionAlias(): void
    {
        $this->registerCentralConnectionAlias();
    }
}
