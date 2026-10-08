<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Routing\Route;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleOperation;

/** A route's resolved gate, kept server-side while a nav node is projected. */
final readonly class SeatGateResolution
{
    public function __construct(
        public SeatGateKind $kind,
        public ?Route $route,
        public ?ResourceDefinition $resource = null,
        public ?ParticleOperation $operation = null,
    ) {}
}
