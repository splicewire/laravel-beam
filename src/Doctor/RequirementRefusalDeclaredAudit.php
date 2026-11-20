<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * **Every operation a host attached a Requirement to must refuse with {@see RequirementRefusalData} at 428**
 * (agreements 04, ADR-0247 amendment).
 *
 * {@see ParticleOperation::declaredErrors()} implies `428 => RequirementRefusalData::class` for every behavior
 * {@see RequirementDeclarations} declares, so the usual case needs nothing from the op's author. The one case it cannot
 * imply is an author who already declared a DIFFERENT body at 428: the implied slot never overwrites the author's, and
 * the controller throws a LogicException the first time a requirement is outstanding there. This audit names that op
 * before a request does.
 *
 * Registered `gate: true`: the conflict has one reading and a runtime throw behind it. Under the core
 * {@see \Splicewire\Beam\Agreements\NullRequirementDeclarations} no behavior is declared, the population is empty and the
 * audit passes, so a host without the agreements seam is unaffected. A pass rather than an inconclusive: "no op carries a
 * conflicting 428" is the measured answer, not a missing one.
 *
 * ⚠️ It reads the BOOTED registry, like {@see UngatedOperationAudit}: the question is what THIS host mounted.
 */
class RequirementRefusalDeclaredAudit implements DoctorAudit
{
    public const CHECK = 'requirements.refusal-declared';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected RequirementDeclarations $declarations,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $population = [];
        $conflicting = [];

        foreach ($this->operations->unfiltered()->matches('beam.particle.operations') as $op) {
            if (! $op instanceof ParticleOperation || ! $this->declarations->declares($op->key())) {
                continue;
            }

            $population[] = $op;
            if (($op->declaredErrors()[428] ?? null) !== RequirementRefusalData::class) {
                $conflicting[] = $op;
            }
        }

        if ($conflicting === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d registered particle operation%s carr%s a host-attached Requirement, and each refuses with %s at 428.',
                count($population),
                count($population) === 1 ? '' : 's',
                count($population) === 1 ? 'ies' : 'y',
                RequirementRefusalData::class,
            ))];
        }

        return [Finding::fail(self::CHECK, sprintf(
            '%d of %d operations carrying a host-attached Requirement declare a different body at errors:428, so the '
            ."implied %s cannot apply and the first outstanding requirement throws:\n%s\n\nDeclare "
            .'`errors: [428 => RequirementRefusalData::class]` on the op, or detach the Requirement from its behavior.',
            count($conflicting),
            count($population),
            RequirementRefusalData::class,
            implode("\n", array_map(
                fn (ParticleOperation $op): string => sprintf('  %-44s errors:428 => %s', $op->key(), $op->errors[428] ?? 'null'),
                $conflicting,
            )),
        ))];
    }
}
