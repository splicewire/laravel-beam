<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;

/**
 * **The `output:` twin of {@see UndeclaredInputAudit}** — the count of operations whose RESPONSE shape is
 * projected by a `respond:` closure that no `output:` declaration describes (api-surface-coherence 127).
 *
 * It inherits 117's settled doctrine wholesale: the estate's answer to *"what does undeclared mean"* is
 * **counted, loudly, until it is zero** — never *rejected*. Warn-level, never a gate. And it is
 * host-dependent by construction, since every offender this map found is a host `ServiceProvider`
 * registration while the package-tier population is clean, which is exactly the class AGENTS.md refuses
 * to let be fatal.
 *
 * ## The unenforced pairing, and why the failure is quiet
 *
 * {@see ParticleOperationController::finish()} is the whole of the runtime contract: if `respond:` is
 * present its return is what ships, **with no comparison to `output:` whatsoever**. The spec/codegen leg
 * reads `output:` and only `output:` ({@see ParticleResponseStrategy}, which bails unless it resolves to
 * a `Data` subclass). The two legs never meet, so a projector can invent any shape it likes and the
 * declaration stays silent about it.
 *
 * Per the codegen gate — no resolved `returns` ⇒ a line in `routes.ts` and nothing else — the result is
 * not a *wrong* client type. It is **no client type**, which is the harder failure to notice. Measured
 * against the flagship's generated client 2026-08-30: `calendars.sweep` declares `output:` and gets
 * `MutationOpts<…SweepResultData>` in `hooks/calendars.ts`; `fragment-url-batches.run` declared none and
 * got a path string in `routes.ts` and no hook at all.
 *
 * ## Two checks, and the KINDS they scope to are different — that asymmetry is the ruling
 *
 * 127 asked whether the audit is kind-scoped. The answer is *per direction*, and it is read off
 * `finish()` rather than off the convention's exemplar docblock:
 *
 *   - **`respond:` without `output:` is UNIVERSAL** ({@see CHECK_PROJECTED}). `finish()`'s own docblock
 *     is explicit that *"`respond` is consulted for EVERY kind, not just the queued one"* — so on a Read
 *     just as much as a Task, the projector's return is what reaches the wire and the declaration that
 *     fails to describe it is equally absent. `SweepCalendar`'s rule is written for a Task only because
 *     that is where the exemplar lives, not because the mechanism is.
 *
 *   - **`output:` without `respond:` is TASK-ONLY** ({@see CHECK_UNPROJECTED}). Here the kind genuinely
 *     decides, because only a Task has a framework default that fills the gap: its `handle` returns a
 *     *job*, so with no projector the response is a bare `{ queued: true|false }` and a declared
 *     `output:` is **false** rather than absent — a wrong type, which is worse. A Read/Write/Stream
 *     `handle` returns the payload itself, so `output:` with no `respond:` is the NORMAL, correct shape.
 *
 * Measured 2026-08-31 at the flagship, which is why this is two checks and not one: **20 of 36
 * registered operations declare `output:` and no `respond:`, and 18 of the 20 are Read/Write/Stream** —
 * a universal inverse check would report eighteen correct declarations as findings on its first run.
 * That is the same reasoning 117 used to keep its two axes apart, applied to two directions of one axis:
 * folding them would let one direction's warn mask the other's pass.
 *
 * ## Registry-side only, and the static twin is REFUSED here with a number
 *
 * 117 established the split — a booted registry reads *readiness*, a static scan reads *blast radius*,
 * because a package's declarations are invisible at a host that never registers them. That gap is
 * measured shut on this axis, in both directions:
 *
 *   - Statically, **five** op classes estate-wide pair a `respond()` convention method with `#[ParticleOp]`
 *     (`laravel-beam-calendars` `Ops/SweepCalendar`, `laravel-beam-notifications` `Ops/ReplayNotificationStatusOp`,
 *     `laravel-beam-ux` `Workflow/EntryWorkflowTransitionOp` + `Particle/EntryBodySaveOp`, `tower`
 *     `Determination/Surface/CorroborateSpecOp`). **All five declare `output:`** — at package tier the
 *     convention holds perfectly, so a static arm would have nothing to report.
 *   - All five are also REGISTERED at the flagship, so the booted reading already covers the entire
 *     estate's respond-bearing population — and it additionally sees the two offenders a static scan
 *     structurally cannot, because they are closures inside a provider rather than attributes on a class.
 *
 * The trigger that would reopen it, stated so the refusal cannot quietly expire: a package shipping a
 * respond-bearing op that no host registers. The shape to reach for then is 121's, not a beam-side
 * scanner — a package-local static guard in the declaring package, which is what that ticket measured
 * and shipped after its own static twin was refused.
 *
 * ## `ACKNOWLEDGED` — a `respond:` that returns a RESPONSE has no `output:` to declare
 *
 * `finish()` has three arms, and only the first is a declarable shape: a `Data` return is enveloped, an
 * already-built response passes **through untouched**, and anything else is returned as-is. The
 * pass-through is load-bearing rather than defensive — `finish()`'s docblock names *"one carrying a
 * specific accepted status code that a declared payload slot has no channel to express"* among the live
 * reasons — and an operation whose projector takes it has no `Data` class to name, because its wire body
 * is not a `Data` envelope at all.
 *
 * That is a DECISION, not residue, so it takes {@see UndeclaredInputAudit}'s carve-out shape exactly: a
 * key plus a reason, reported under its own heading rather than silently subtracted, and reported as
 * **stale** the moment the key declares an `output:` — so the acknowledgement cannot outlive its reason.
 * String keys only; beam-core never learns a consumer's class, and no fourth declaration state enters
 * the attribute surface for the sake of one operation.
 */
class UndeclaredOutputAudit implements DoctorAudit
{
    /** `respond:` present, no resolvable `output:` — the projected shape nothing describes. */
    public const CHECK_PROJECTED = 'particle.operation-output';

    /** A Task declaring `output:` with no `respond:` — the declared shape nothing produces. */
    public const CHECK_UNPROJECTED = 'particle.task-output-projector';

    /**
     * Operation keys whose missing `output:` is a DECISION this audit has accepted, each with the reason.
     *
     * Every entry here is a `respond:` that returns a built RESPONSE rather than a payload — `finish()`'s
     * pass-through arm — for which there is no `Data` class to name. See the class docblock.
     *
     * @var array<string, string>
     */
    public const ACKNOWLEDGED = [
        'declarations.redetermine' => 'the accepted-status escape hatch — `respond` returns a built '
            .'202 response carrying `{message}`, not a payload, so `finish()` passes it through '
            .'untouched and there is no Data envelope for an `output:` to describe. The caller\'s '
            .'contract has always been the 202 message; publishing the Task\'s `{queued}` payload '
            .'instead would be a new behaviour dressed as a declaration.',
    ];

    public function __construct(protected ParticleOperationRegistry $operations) {}

    /** @return list<Finding> */
    public function run(): array
    {
        /** @var list<ParticleOperation> $all */
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');

        return [$this->projected($all), $this->unprojected($all)];
    }

    /**
     * Operations carrying a `respond:` whose projected shape no `output:` describes — universal across
     * kinds, because `finish()` consults `respond` on all four.
     *
     * @param  list<ParticleOperation>  $all
     */
    private function projected(array $all): Finding
    {
        $projectors = array_values(array_filter($all, fn (ParticleOperation $op): bool => $op->respond !== null));

        if ($projectors === []) {
            return Finding::inconclusive(self::CHECK_PROJECTED, 'No operation registered in this host '
                .'supplies a `respond:` projector, so there is no projected shape to describe. Nothing '
                .'was measured — this is not a clean reading of a populated axis.');
        }

        $outstanding = [];
        $acknowledged = [];
        $stale = [];

        foreach ($projectors as $op) {
            $key = $op->key();
            $isAcknowledged = array_key_exists($key, self::ACKNOWLEDGED);

            if ($this->resolvesOutput($op)) {
                if ($isAcknowledged) {
                    $stale[] = $key;
                }

                continue;
            }

            if ($isAcknowledged) {
                $acknowledged[] = $key;

                continue;
            }

            $outstanding[$key] = $op->kind;
        }

        $declared = count($projectors) - count($outstanding) - count($acknowledged);

        if ($outstanding === [] && $stale === []) {
            return Finding::pass(self::CHECK_PROJECTED, sprintf(
                '%d/%d operations supplying a `respond:` projector DECLARE the shape it returns%s. Every '
                .'projected response is described, so the spec leg and the generated client say what the '
                .'runtime actually ships.',
                $declared,
                count($projectors),
                $acknowledged === []
                    ? ''
                    : sprintf(', and the %d acknowledged carve-out%s (%s) returns a built response rather '
                        .'than a payload, which has no `output:` to name',
                        count($acknowledged),
                        count($acknowledged) === 1 ? '' : 's',
                        implode(', ', $acknowledged)),
            ));
        }

        $lines = [];

        foreach ($outstanding as $key => $kind) {
            $lines[] = sprintf('  %-38s (%s) → declare `output: <Something>Data::class`', $key, $kind->value);
        }

        foreach ($stale as $key) {
            $lines[] = sprintf('  %-38s → STALE acknowledgement: it now resolves an `output:`, so its entry '
                .'in UndeclaredOutputAudit::ACKNOWLEDGED should be deleted', $key);
        }

        return Finding::warn(self::CHECK_PROJECTED, sprintf(
            '%d of %d operations supplying a `respond:` projector declare no resolvable `output:`, so the '
            ."shape that reaches the wire is described NOWHERE.\n\n"
            .'This does not produce a wrong client type, it produces NO client type: with no resolved '
            .'`returns` the operation gets a line in `routes.ts` and no typed hook, leaving every caller '
            ."to hand-type the response. `respond`'s return is shipped unexamined — `finish()` never "
            ."compares it to `output:` — so nothing else will catch the omission.\n\n"
            .'Declare the Data class the projector returns. If it returns a built RESPONSE rather than a '
            .'payload (the accepted-status / binary-download / redirect escape hatch), there is nothing '
            ."to declare — record it in UndeclaredOutputAudit::ACKNOWLEDGED with its reason%s:\n%s",
            count($outstanding),
            count($projectors),
            $acknowledged === []
                ? ''
                : sprintf(' (%d already acknowledged: %s)', count($acknowledged), implode(', ', $acknowledged)),
            implode("\n", $lines),
        ));
    }

    /**
     * Tasks declaring an `output:` that no `respond:` produces — the inverse, and the direction where a
     * declaration is FALSE rather than absent.
     *
     * Task-scoped deliberately: only a Task's `handle` returns a job rather than the payload, so only a
     * Task has a framework default (`{ queued: … }`) that contradicts the declaration. See the class
     * docblock for the eighteen correct Read/Write/Stream declarations a universal check would libel.
     *
     * @param  list<ParticleOperation>  $all
     */
    private function unprojected(array $all): Finding
    {
        $tasks = array_values(array_filter(
            $all,
            fn (ParticleOperation $op): bool => $op->kind === OperationKind::Task,
        ));

        if ($tasks === []) {
            return Finding::inconclusive(self::CHECK_UNPROJECTED, 'No `kind: Task` operation is registered '
                .'in this host, and this check reads no other kind. Nothing was measured — this is not a '
                .'clean reading of a populated axis.');
        }

        $false = [];

        foreach ($tasks as $op) {
            if ($op->respond === null && $this->resolvesOutput($op)) {
                $false[] = $op->key();
            }
        }

        if ($false === []) {
            return Finding::pass(self::CHECK_UNPROJECTED, sprintf(
                'All %d registered Task%s that declare an `output:` also supply the `respond:` projector '
                .'that produces it. None ships a declaration contradicted by the bare `{ queued: … }` a '
                .'projector-less Task actually answers with.',
                count($tasks),
                count($tasks) === 1 ? '' : 's',
            ));
        }

        return Finding::warn(self::CHECK_UNPROJECTED, sprintf(
            '%d of %d registered Tasks declare an `output:` and supply no `respond:` to produce it. A '
            .'projector-less Task answers with a bare `{ queued: true|false }`, so the declaration — and '
            ."therefore the generated client's type for the call — is simply FALSE.\n\n"
            .'This is the worse half of the pairing: a wrong type rather than an absent one, and the '
            ."caller has no way to discover it short of reading the response.\n\n"
            .'Add a `respond:` returning the declared class (`Splicewire\\Beam\\Calendars\\Ops\\SweepCalendar` '
            ."is the exemplar), or delete the `output:` and let the queued envelope stand undescribed:\n%s",
            count($false),
            count($tasks),
            implode("\n", array_map(fn (string $key): string => sprintf('  %-38s → supply `respond:` or drop `output:`', $key), $false)),
        ));
    }

    /**
     * Whether an operation's `output:` resolves to something the spec leg will actually describe.
     *
     * Mirrors {@see ParticleResponseStrategy} exactly, and must keep mirroring it: a single class-string
     * counts only when it is a `Data` subclass (the strategy bails otherwise and stashes a generic
     * object), and a Stream's event-name map counts as declared in whatever arity it carries.
     */
    private function resolvesOutput(ParticleOperation $operation): bool
    {
        $output = $operation->output;

        if (is_array($output)) {
            return $output !== [];
        }

        return is_string($output) && is_subclass_of($output, Data::class);
    }
}
