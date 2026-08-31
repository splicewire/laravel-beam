<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\BeamDoctorCommand;
use Splicewire\Beam\Particle\Attributes\UngatedWriteDeclarations;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * **A `kind: Write` operation declaring no `ability:` FAILS.** The gating half of
 * {@see UngatedOperationAudit}, which keeps the advisory count for every other kind.
 *
 * ## Why this one gates when its sibling does not
 *
 * particle-write-surface ticket 02 closed the `kind: Write` residue at the flagship — nine null-ability
 * write ops went to zero — and left the estate with an instrument that reported the number and nothing
 * that failed on it. That is this estate's signature defect wearing its other face: not *an instrument
 * that reports success by not running*, but one that runs, reports, and is not read. A new
 * `kind: Write` op declaring `ability: null` would land green and the count would climb back.
 *
 * AGENTS.md's rule licenses this and also bounds it: throw only on what *"the declaration's author
 * could have gotten right without knowing which host would load it"*. **"Did this declaration name an
 * ability?" is exactly that** — it is grammar on the declaration, not a fact about the host. What the
 * same rule forbids is the adjacent question *"does that ability resolve in this host?"*, which is a
 * host fact and stays where it already is (advisory, and measured op by op at the host).
 *
 * ## Why a GATE and not a registration throw
 *
 * The loudest option — reject `null` in {@see ParticleOperation}'s constructor — was refused, and not
 * on general caution. AGENTS.md's *"a check whose answer depends on the host must not throw"* section
 * exists because a boot-time throw over a registry fact took `~/Herd/tower` off the air entirely, and
 * the repair was to downgrade it to an audit. A throw here would be narrower than that one, but it
 * shares the fatal property: the blast radius is *boot*, so a host that hits it cannot run the command
 * that would tell it why. A gate finding fails the exit code of a command whose whole job is to be
 * run — {@see BeamDoctorCommand} — and leaves the host bootable while it is
 * repaired. Loud where loudness is cheap; silent where it would strand.
 *
 * ⚠️ **Measured before promoting, because a gate that reddens live hosts is an outage of a different
 * kind.** 2026-08-31, booting each `~/Herd/*` root on disk (never `symlinks.json`, which cannot see
 * `beam`, `satellite` or `tower`) and reflecting the live registry: **15 roots resolve this registry,
 * and every one reads `kind: Write` with a null ability = 0.** (Of the remaining six `~/Herd` roots
 * with an `artisan`, five do not install beam at all and one vendors a beam predating
 * `ParticleOperationRegistry::unfiltered()`.) The flagship carried 36 operations with 4 null
 * abilities, **every one of them `kind: Read`** — a concurrent session closed those four later the
 * same day, which changes the count and not the point. So this promotion fails nothing that exists
 * today; it only refuses the next one.
 *
 * ## Write only, deliberately
 *
 * `Read` is excluded on {@see OperationKind}'s own account: a Read's gate IS its query scope, so a Read
 * declaring no ability may be entirely legitimate and has no fix to prescribe. All four survivors at
 * the flagship are Reads. `Task` and `Stream` are excluded because nothing has measured them; a check
 * that fails a build is the wrong place to guess, and {@see UngatedOperationAudit} keeps counting them
 * in the meantime. Widening this is a decision with evidence behind it or not at all.
 *
 * ## Reach, and its one blind spot
 *
 * Registry-side, on {@see UngatedOperationAudit}'s precedent: it sees what THIS host actually
 * registered, including imperative `new ParticleOperation(...)` declarations that no file scan can
 * find. What it cannot see is a package declaration no host installs — which is the right trade for a
 * readiness check and the wrong one for a package author, so the other half of this gate lives in
 * {@see UngatedWriteDeclarations}, a source scan a declaring package runs in its own suite with no
 * host at all. The two are complements: neither subsumes the other.
 */
class UngatedWriteOperationAudit implements DoctorAudit
{
    public const CHECK = 'particle.write-operation-gate';

    public function __construct(protected ParticleOperationRegistry $operations) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');

        if ($all === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle operations are registered in this host.')];
        }

        $writes = array_values(array_filter(
            $all,
            fn (ParticleOperation $op): bool => $op->kind === OperationKind::Write,
        ));

        $ungated = array_values(array_filter(
            $writes,
            fn (ParticleOperation $op): bool => $op->gateUndeclared(),
        ));

        if ($writes === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'None of the %d registered particle operations is `kind: Write`, so this gate had no '
                .'population to measure.',
                count($all),
            ))];
        }

        if ($ungated === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d `kind: Write` particle operations declare an `ability:` (a token, or an explicit '
                .'`false`). %d operations registered in total.',
                count($writes),
                count($all),
            ))];
        }

        return [Finding::fail(self::CHECK, sprintf(
            '%d of %d `kind: Write` particle operations declare no `ability:` — neither a token nor an '
            .'explicit `false`. A write operation that names no authorization is gated only by whatever '
            .'middleware its mount happens to carry, and the declaration cannot be read to find out '
            ."which.\n\nDeclare the token this host will grant, or `ability: false` with a docblock "
            .'saying why (the shape `Splicewire\\Beam\\Accounts\\Ops\\StopImpersonating` argues). The '
            ."derived permission name each would take is shown beside it:\n%s",
            count($ungated),
            count($writes),
            implode("\n", array_map(
                fn (ParticleOperation $op): string => sprintf(
                    '  %-44s → ability: \'%s\'',
                    $op->key(),
                    $op->permissionName(),
                ),
                $ungated,
            )),
        ))];
    }
}
