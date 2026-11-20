<?php

namespace Splicewire\Beam\Agreements;

/**
 * Which behaviors carry a Requirement, and which (agreements 04 and 05, ADR-0247 amendments).
 *
 * A host may attach a Requirement to an operation it does not own, by behavior key ({@see \Splicewire\Beam\Particle\ParticleOperation::key()},
 * `{resource}.{name}`). That operation's author never declared `errors: [428 => RequirementRefusalData::class]`, so the
 * port is how the op learns it may refuse at 428: {@see \Splicewire\Beam\Particle\ParticleOperation::declaredErrors()}
 * implies the slot for every behavior this port declares, and every reader of an op's declared refusals reads that.
 *
 * Agreements 05 adds the declarations' own `requires:` slot and the resource behaviors `{resource}:read` and
 * `{resource}:write`, and two ADDITIVE reads: the Requirements one behavior carries, and every declared behavior at once
 * (the route manifest and the ability map read them). In every implementation `declares($b)` equals
 * `requirementsFor($b) !== []`.
 *
 * Beam's default is {@see SlotRequirementDeclarations}: it answers from the `requires:` slots of the registered
 * operations and resources, so a host with no slot anywhere declares nothing and is byte-identical to one before the port
 * existed. {@see NullRequirementDeclarations} declares nothing at all. An agreements seam binds its own, and reads the
 * slots through {@see SlotRequirementDeclarations}.
 */
interface RequirementDeclarations
{
    public function declares(string $behavior): bool;

    /** @return list<Requires> the Requirements this behavior carries; [] when none */
    public function requirementsFor(string $behavior): array;

    /** @return array<string, list<Requires>> every behavior that carries a Requirement => its Requirements */
    public function all(): array;
}
