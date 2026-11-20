<?php

namespace Splicewire\Beam\Agreements;

/**
 * Which behaviors a host has attached a Requirement to (agreements 04, ADR-0247 amendment).
 *
 * A host may attach a Requirement to an operation it does not own, by behavior key ({@see \Splicewire\Beam\Particle\ParticleOperation::key()},
 * `{resource}.{name}`). That operation's author never declared `errors: [428 => RequirementRefusalData::class]`, so the
 * port is how the op learns it may refuse at 428: {@see \Splicewire\Beam\Particle\ParticleOperation::declaredErrors()}
 * implies the slot for every behavior this port declares, and every reader of an op's declared refusals reads that.
 *
 * The core default is {@see NullRequirementDeclarations}: it declares nothing, so a host without the agreements seam is
 * byte-identical to one before the port existed.
 */
interface RequirementDeclarations
{
    public function declares(string $behavior): bool;
}
