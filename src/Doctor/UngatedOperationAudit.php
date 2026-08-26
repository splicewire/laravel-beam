<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * **Every registered {@see ParticleOperation} must DECLARE its authorization** — a token, or `false`
 * for deliberately-ungated. `null` is neither, and it is what this audit counts
 * (particle-operation-surface ticket 03).
 *
 * ## Why an audit and not a constructor throw
 *
 * The obvious move — reject `null` at registration — was measured and refused. 14 of the 21 operations
 * registered in the flagship app declare nothing today, so a throw would fail boot, and the other
 * obvious move (derive the permission name and check it) would 403 the same fourteen: an ability
 * outside a host's declared universe is UNDEFINED, and undefined is denied. Registry-kernel ticket 27
 * D1 pinned exactly that failure one seam over, which is why `EntitlementRegistryAuthorizer` ships
 * uninstalled.
 *
 * So the residue closes op by op, and this is the thing that keeps the count honest in between. It is
 * the same schedule `ParticleOperation::$input`'s `null` → `false` flip is under, with one difference
 * that matters: that flip's gate was written as prose and **nobody has measured the count since**. This
 * one is a check.
 *
 * ## What a finding is worth
 *
 * A warn, not a fail. An undeclared operation is not necessarily an OPEN one — the mount's route
 * middleware may gate it, and for several of the live fourteen it does. What is true of all of them is
 * that the declaration cannot be read to find out, so an audit of the surface has to go and look at
 * routes. The finding names the permission name the operation WOULD get, so the fix is a line to paste
 * rather than a decision to re-derive.
 *
 * ⚠️ It reads the BOOTED registry, so it sees what this host actually registered — a package's
 * declaration that no host installs is correctly invisible here, and a host that registers its own
 * operations inline is correctly included. That is the opposite trade from a static scan and it is the
 * right one for a readiness check: the question is "what is mounted in THIS app", not "what exists in
 * the estate".
 */
class UngatedOperationAudit implements DoctorAudit
{
    public const CHECK = 'particle.operation-gate';

    public function __construct(protected ParticleOperationRegistry $operations) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');

        $undeclared = array_values(array_filter(
            $all,
            fn (ParticleOperation $op): bool => $op->gateUndeclared(),
        ));

        $declared = count($all) - count($undeclared);

        if ($all === []) {
            return [Finding::pass(self::CHECK, 'No particle operations are registered in this host.')];
        }

        if ($undeclared === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d/%d particle operations declare their authorization. The `null` residue is empty, '
                .'which is the gate ParticleOperation::$ability\'s null → derived flip is waiting on.',
                $declared,
                count($all),
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d particle operations declare no `ability:` — neither a token nor an explicit '
            .'`false`. Each is gated only by whatever middleware its mount carries, and the declaration '
            ."cannot be read to find out which.\n\nDeclare a token, or `ability: false` with a docblock "
            .'saying why (the shape `Splicewire\\Beam\\Accounts\\Ops\\StopImpersonating` argues). The '
            ."derived permission name each would take is shown beside it:\n%s",
            count($undeclared),
            count($all),
            implode("\n", array_map(
                fn (ParticleOperation $op): string => sprintf(
                    '  %-44s → ability: \'%s\'',
                    $op->key(),
                    $op->permissionName(),
                ),
                $undeclared,
            )),
        ))];
    }
}
