<?php

namespace Splicewire\Beam\Seed;

/**
 * The scenario one seed run uses, and the rung of {@see SeedingScenarioRegistry::rungs()} that chose it, so the
 * command can say both on its first line.
 */
final class SelectedSeedingScenario
{
    public function __construct(
        public readonly SeedingScenario $scenario,
        public readonly string $rung,
    ) {}
}
