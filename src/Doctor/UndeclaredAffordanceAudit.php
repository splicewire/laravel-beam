<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Frame\ParticleResourceActions;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * **The `affordance:` twin of {@see UndeclaredInputAudit}** (particle-operation-surface 21, ADR-0223) —
 * the count of `Write` operations that never said whether frame should draw them.
 *
 * `affordance:` is three-state for the reason `input:` and `ability:` are: an op declaring
 * `affordance: false` has DECIDED it is API-only, and an op declaring nothing has not decided anything. The
 * two render identically (no button), so only a count can tell them apart. The estate's answer to
 * *"what does undeclared mean"* stays what 117 settled: **counted, loudly, until it is zero** — warn-level,
 * never a gate, and never a boot failure, since the whole existing population predates the slot.
 *
 * Scoped to `Write`, on purpose. A Write is the kind a person presses a button for; a Read is fetched by a
 * screen, a Stream is subscribed to, and a Task is queued by something that already knows it exists. Asking
 * every Read to say `affordance: false` would make the finding a list of reads and hide the writes in it.
 *
 * A second check reports a declaration frame CANNOT honour: an op that declares an affordance but has no
 * placement (a `ParentSubject` op, out of scope by ticket 21 decision 2, or an unusual coordinate set).
 * That is a declaration the author can correct, so it warns in its own check rather than hiding among the
 * undeclared. An op that is simply not mounted in this host is NOT reported — mounting is the host's call.
 */
class UndeclaredAffordanceAudit implements DoctorAudit
{
    /** A `Write` op declaring no `affordance:` at all. */
    public const CHECK_UNDECLARED = 'particle.operation-affordance';

    /** An op declaring an affordance frame has no placement for. */
    public const CHECK_UNPLACEABLE = 'particle.operation-affordance-placement';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected ?ParticleResourceActions $actions = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        /** @var list<ParticleOperation> $all */
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');

        return [$this->undeclared($all), $this->unplaceable($all)];
    }

    /** @param list<ParticleOperation> $all */
    private function undeclared(array $all): Finding
    {
        $writes = array_values(array_filter($all, fn (ParticleOperation $op): bool => $op->kind === OperationKind::Write));

        if ($writes === []) {
            return Finding::inconclusive(self::CHECK_UNDECLARED, 'No `kind: Write` operation is registered in '
                .'this host, and this check reads no other kind. Nothing was measured — this is not a clean '
                .'reading of a populated axis.');
        }

        $undeclared = array_values(array_map(
            fn (ParticleOperation $op): string => $op->key(),
            array_filter($writes, fn (ParticleOperation $op): bool => $op->affordanceUndeclared()),
        ));

        if ($undeclared === []) {
            return Finding::pass(self::CHECK_UNDECLARED, sprintf(
                'All %d registered Write operation%s declare `affordance:` — drawn as a frame action, or '
                .'`false` for API-only. No omission reads as a decision.',
                count($writes),
                count($writes) === 1 ? '' : 's',
            ));
        }

        return Finding::warn(self::CHECK_UNDECLARED, sprintf(
            '%d of %d registered Write operations declare no `affordance:`, so whether frame should draw them '
            .'is undecided. Undeclared renders no button, exactly as `false` does — which is why it is counted: '
            ."an omission and a decision must not be spelled the same.\n\n"
            ."Declare `affordance: 'action'` (or `new ActionAffordance(label: …)`) to draw it on the resource's "
            ."framed screen, or `affordance: false` if it is API-only:\n%s",
            count($undeclared),
            count($writes),
            implode("\n", array_map(fn (string $key): string => '  '.$key, $undeclared)),
        ));
    }

    /** @param list<ParticleOperation> $all */
    private function unplaceable(array $all): Finding
    {
        $declared = array_values(array_filter($all, fn (ParticleOperation $op): bool => $op->actionAffordance() !== null));

        if ($declared === []) {
            return Finding::inconclusive(self::CHECK_UNPLACEABLE, 'No registered operation declares an action '
                .'affordance, so there is no placement to check.');
        }

        $actions = $this->actions ?? app(ParticleResourceActions::class);
        $unplaceable = array_values(array_map(
            fn (ParticleOperation $op): string => $op->key(),
            array_filter($declared, fn (ParticleOperation $op): bool => $actions->scope($op) === null),
        ));

        if ($unplaceable === []) {
            return Finding::pass(self::CHECK_UNPLACEABLE, sprintf(
                'All %d operation%s declaring an action affordance address no record or one `{id}` record, '
                .'which frame places beside "New" or on each row and detail page.',
                count($declared),
                count($declared) === 1 ? '' : 's',
            ));
        }

        return Finding::warn(self::CHECK_UNPLACEABLE, sprintf(
            '%d operation%s declare an action affordance frame has no placement for, so no button is drawn. '
            ."Frame places a subject-free op beside the list's \"New\" and an `{id}` op on each row and on the "
            .'detail page; a `ParentSubject` op addresses a record through an edge frame has no concept of '
            ."(ADR-0223). Declare `affordance: false`, or give the op a placeable subject:\n%s",
            count($unplaceable),
            count($unplaceable) === 1 ? '' : 's',
            implode("\n", array_map(fn (string $key): string => '  '.$key, $unplaceable)),
        ));
    }
}
