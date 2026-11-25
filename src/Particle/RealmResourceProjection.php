<?php

namespace Splicewire\Beam\Particle;

use Schemastud\Frame\Registry\ResourceDefinition;

/** The declaration indexes shared by one realm's manifest build. Actor decisions stay with callers. */
final readonly class RealmResourceProjection
{
    /** @var array<string, ResourceDefinition> */
    public array $byKey;

    /** @var array<string, ResourceDefinition> */
    public array $byRouteName;

    /** @param list<ResourceDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $byKey = [];
        $byRouteName = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
            // SeatGate has always selected the first declaration matching a list route.
            $byRouteName[ListRouteName::of($definition)] ??= $definition;
        }

        $this->byKey = $byKey;
        $this->byRouteName = $byRouteName;
    }
}
