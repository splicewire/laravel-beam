<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Graph\GraphOperations;
use Splicewire\Beam\Particle\OperationKind;
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
 * ## ⚠️ `kind: Write` MOVED OUT of this audit, and it gates
 *
 * As of particle-write-surface ticket 02 this audit counts every kind EXCEPT `Write`.
 * {@see UngatedWriteOperationAudit} owns that one, registered `gate: true`, and a null-ability write
 * operation now FAILS `splicewire:beam:doctor`. Two reasons the split is a split rather than a
 * severity branch inside this class:
 *
 *   1. **The gate flag is per REGISTRATION, not per finding.** On a gating registration a `warn` still
 *      fails the runner at a lowered `--floor` ({@see RegistryConformanceAudit::gates()} states the
 *      same mechanical fact). Promoting this class wholesale would drag the legitimate `kind: Read`
 *      residue into a failure the moment anyone ran `--floor=warn`.
 *   2. **The two questions have different answers.** A `Write` with no ability is a defect with a fix.
 *      A `Read` with no ability may be entirely correct — {@see OperationKind}'s docblock says a Read's
 *      gate IS its query scope — so it is a work-list line, not a build break. All four survivors at
 *      the flagship (2026-08-31) are Reads, which is the measurement that made the distinction rather
 *      than an argument that predicted it.
 *
 * So the count this audit keeps is now the **non-write** residue, and its emptiness is no longer the
 * whole gate on {@see ParticleOperation::$ability}'s `null` → derived-name flip — it is the remaining
 * half of it.
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

    /**
     * Why the four graph reads {@see GraphOperations} derives are ACKNOWLEDGED rather than residue.
     *
     * The same shape as {@see UndeclaredInputAudit::ACKNOWLEDGED}, with the recognition structural rather
     * than a key list, because the keys are per host resource (`silos.graph`, `regions.graph.path`):
     * an operation is acknowledged when {@see GraphOperations::isDerived()} says beam derived it. A host's
     * own null-ability Read, at any key, stays outstanding.
     *
     * Not a fixed `viewAny` either, measured rather than assumed: {@see \Splicewire\Beam\Authorization\ResourceReadGuard}
     * asks `viewAny` only when a read policy is bound, so a fixed token would 403 every policy-less
     * resource (an undefined ability is a denied one, registry-kernel 27 D1). And not `false`: these are
     * gated, by the resource read gate plus the backing's scoped store, which
     * {@see \Splicewire\Beam\Graph\GraphOperationHandler} applies before it reads anything.
     */
    public const ACKNOWLEDGED_DERIVED_GRAPH = 'derived by GraphOperations for a resource whose backing graphs its records; '
        .'gated by the resource read gate (ResourceReadGuard::inspectRead, fail-closed) and the scoped store, the way '
        .'a list read is, so `ability:` is left undeclared on purpose rather than spelled `false`';

    public function __construct(protected ParticleOperationRegistry $operations) {}

    /** @return list<Finding> */
    public function run(): array
    {
        // One unfiltered view for both the population and the derived-op recognition (graph-operations
        // 07): a filter that hid a derived op from registrantOf() would otherwise report it outstanding.
        $unfiltered = $this->operations->unfiltered();
        assert($unfiltered instanceof ParticleOperationRegistry, 'unfiltered() clones the registry it is called on');
        $all = $unfiltered->matches('beam.particle.operations');

        // `kind: Write` is UngatedWriteOperationAudit's population, not this one's — see the class
        // docblock for why the split is a split. Excluded from the denominator too: a "4 of 36" that
        // counted operations this audit would never report on reads as a coverage figure and is not one.
        $population = array_values(array_filter(
            $all,
            fn (ParticleOperation $op): bool => $op->kind !== OperationKind::Write,
        ));

        $undeclared = [];
        $acknowledged = [];

        foreach ($population as $op) {
            if (! $op->gateUndeclared()) {
                continue;
            }

            // Reported under its own heading, never silently subtracted (UndeclaredInputAudit's rule).
            if (GraphOperations::isDerived($op, $unfiltered)) {
                $acknowledged[] = $op->key();
            } else {
                $undeclared[] = $op;
            }
        }

        $declared = count($population) - count($undeclared) - count($acknowledged);

        // Counted inside the population, never as an extra one (UndeclaredInputAudit's phrasing): "0/4
        // declare …, and the 4 acknowledged …" rather than "0/4 … 4 more", which reads as eight.
        $acknowledgedPhrase = sprintf('the %d acknowledged derived graph read%s (%s)',
            count($acknowledged),
            count($acknowledged) === 1 ? '' : 's',
            implode(', ', $acknowledged));
        $acknowledgedVerb = count($acknowledged) === 1 ? 'is' : 'are';

        // The acknowledged reads are still `ability: null`. The null → derived flip would turn each into a
        // token no host defines, and an undefined ability is a denied one (registry-kernel 27 D1), so the
        // flip is not clear while any are registered: it has to exempt them.
        $flipNote = $acknowledged === []
            ? 'Both halves empty is the gate ParticleOperation::$ability\'s null → derived flip is waiting on.'
            : sprintf('The null → derived flip is still not clear: it must exempt %s, which %s null by design '
                .'and would 403 under a derived token no host defines.', $acknowledgedPhrase, $acknowledgedVerb);

        if ($all === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle operations are registered in this host.')];
        }

        if ($population === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'All %d registered particle operations are `kind: Write`, which %s gates; this audit had '
                .'no population to measure.',
                count($all),
                UngatedWriteOperationAudit::class,
            ))];
        }

        if ($undeclared === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d/%d non-write particle operations declare their authorization%s. The unacknowledged '
                .'`null` residue is empty on this half; `kind: Write` is gated separately by %s. %s',
                $declared,
                count($population),
                $acknowledged === []
                    ? ''
                    : sprintf(', and %s %s scope-gated by construction: %s',
                        $acknowledgedPhrase, $acknowledgedVerb, self::ACKNOWLEDGED_DERIVED_GRAPH),
                UngatedWriteOperationAudit::class,
                $flipNote,
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d non-write particle operations declare no `ability:` — neither a token nor an explicit '
            .'`false`. Each is gated only by whatever middleware its mount carries, and the declaration '
            ."cannot be read to find out which.\n\nDeclare a token, or `ability: false` with a docblock "
            .'saying why (the shape `Splicewire\\Beam\\Accounts\\Ops\\StopImpersonating` argues). The '
            ."derived permission name each would take is shown beside it:\n%s%s",
            count($undeclared),
            count($population),
            implode("\n", array_map(
                fn (ParticleOperation $op): string => sprintf(
                    '  %-44s → ability: \'%s\'',
                    $op->key(),
                    $op->permissionName(),
                ),
                $undeclared,
            )),
            // After the list, never between its header and its lines.
            $acknowledged === []
                ? ''
                : "\n\n".sprintf('Not counted as residue: %s, which %s scope-gated by construction: %s. %s',
                    $acknowledgedPhrase, $acknowledgedVerb, self::ACKNOWLEDGED_DERIVED_GRAPH, $flipNote),
        ))];
    }
}
