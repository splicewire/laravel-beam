<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\OperationSubjectModel;
use Splicewire\Beam\Routing\IdConstraint;
use Throwable;

/**
 * **A declared {@see IdConstraint} must agree with the model's real key type**
 * (particle-operation-surface ticket 14, gate 1). It reads each registered operation's `idConstraint:`
 * and compares it against what the resolved model actually keys on — `HasUuids`, `HasUlids`, or an
 * auto-incrementing integer.
 *
 * ## Advisory, and the reason is this estate's own throw/advise rule
 *
 * A model's key type is a fact about the **HOST**, not grammar the declaration's author could have
 * gotten right without knowing which host would load it. `ImpersonateUser` is declared once in
 * `laravel-beam-accounts` and mounted against `App\Models\User` at every host that installs it; those
 * `User`s do not agree with each other about their key type, and the estate has measured them not
 * agreeing. A throw here takes a host down at boot for a disagreement the package could not have
 * foreseen — which is exactly the failure AGENTS.md records from 2026-08-25.
 *
 * An unrecognised *value* is a different question and is not this audit's: `IdConstraint` is an enum,
 * so a misspelling fails at parse, which is where grammar belongs.
 *
 * ## Its day-one finding was real, and it was the argument for building it
 *
 * `~/Herd/audiostud` declared `'idConstraint' => 'int'` for `operator-customers`, whose model is
 * `App\Models\User` — `HasUuids`. The provider's own docblock asserted the opposite in prose
 * (*"the customer `{id}` is an integer (User PK)"*), so the declaration and the comment defending it
 * were wrong together, and had been for as long as both existed. Nothing could see it because
 * {@see IdConstraint::Int} is inert: only `Uuid` emits a route constraint today, so a wrong `Int`
 * costs nothing at runtime and leaves no trace. **That is precisely why it needs an audit rather than
 * a test** — the defect's whole signature is that it has no signature.
 *
 * ## What "zero" unlocks
 *
 * Ticket 14 gate 3: enforcement of `Ulid`/`Int` is one line in {@see IdConstraint::enforced()}, and it
 * flips when this audit reads zero across the estate. Until then a wrong declaration is a note; after
 * the flip it is a 404 on every route the resource mounts. So this reading is a precondition, not a
 * report.
 *
 * ⚠️ Reads the BOOTED registry, on {@see UngatedOperationAudit}'s precedent — the question is *what is
 * mounted in THIS app*, and only a booted host knows which concrete model a package's declaration
 * resolved to.
 *
 * ⚠️ **It reads the DECLARATION only, so a constraint still passed as `Particle::ops(…, ['idConstraint'
 * => …])` is invisible to it.** That is not an oversight and it is not permanent: the mount option is a
 * migration fallback with a deletion condition ({@see ParticleMounter::op()}),
 * and this audit deliberately measures the *declared* population, because that population is what gate 3
 * flips enforcement on. The practical consequence while both spellings exist: a PASS here means every
 * DECLARED constraint agrees, not that no wrong constraint is reachable. `~/Herd/audiostud`'s two
 * `operator-customers` mounts are exactly that case today — corrected in place, but corrected at the
 * mount, because the two ops they mount are declared in `laravel-beam-accounts`.
 */
class ParticleIdConstraintKeyTypeAudit implements DoctorAudit
{
    public const CHECK = 'particle.id-constraint-key-type';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected ?OperationSubjectModel $models = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $declared = array_values(array_filter(
            $this->operations->unfiltered()->matches('beam.particle.operations'),
            fn (ParticleOperation $op): bool => $op->idConstraint !== null,
        ));

        if ($declared === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle operation in this host declares an `idConstraint:`, so there is nothing to disagree with a key type.')];
        }

        $mismatched = [];

        $models = $this->models ?? new OperationSubjectModel;

        foreach ($declared as $op) {
            // ⚠️ Guarded, and the guard is the point. Reading the model now goes through the
            // RESOURCE, and a `ResourceBacking` class-string is container-resolved to answer
            // `modelClass()` — so a backing whose constructor wants a tenant connection would turn
            // `surgeon:audit` into a stack trace instead of a finding. An audit's answer depends on
            // the host by definition; it reports, it does not throw. Same shape as `keyTypeOf()`'s
            // own `catch (Throwable)` below, and for the same reason.
            try {
                $model = $models->for($op);
            } catch (Throwable) {
                $model = null;
            }

            $actual = $model === null ? null : $this->keyTypeOf($model);

            // `null` = the model could not be resolved or keys on something none of the three cases
            // names (a compound key, a string slug). Reporting that as a mismatch would be reporting
            // this audit's own blind spot as the host's defect.
            if ($actual === null || $actual === $op->idConstraint) {
                continue;
            }

            // `None` is DECLARED unconstrained — an author saying "this `{id}` is deliberately open".
            // The key type does not contradict it; nothing is being asserted about the key.
            if ($op->idConstraint === IdConstraint::None) {
                continue;
            }

            $mismatched[] = sprintf(
                '  %-40s declares %-5s but %s keys on %s',
                $op->key(),
                $op->idConstraint->value,
                $model,
                $actual->value,
            );
        }

        if ($mismatched === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d particle operation%s declare an `idConstraint:` and every one agrees with its '
                .'model\'s real key type. This is the reading ticket 14 gate 3 waits on before '
                .'`Ulid`/`Int` become enforced constraints rather than declarations.',
                count($declared),
                count($declared) === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            "%d of %d declared `idConstraint:` values disagree with the model's actual key type. Each "
            .'is inert today — only `Uuid` emits a route constraint — and each becomes a 404 on every '
            ."mount of that resource the moment ticket 14 gate 3 flips enforcement on.\n\n"
            .'⚠️ Fix the DECLARATION, and check any prose defending it in the same change: the first '
            .'instance of this finding came with a provider docblock asserting the wrong key type in '
            ."words beside the wrong constraint in code.\n\n%s",
            count($mismatched),
            count($declared),
            implode("\n", $mismatched),
        ))];
    }

    /**
     * What a model class really keys on, or `null` when this audit cannot tell.
     *
     * Asked of the class rather than the schema deliberately: `HasUuids`/`HasUlids` are the
     * declaration Laravel's own route binding and key generation read, so agreeing with them is what
     * makes a route constraint correct. A column type read from the connection would be a second
     * source that can disagree with the model, and the model is the one that wins at runtime.
     */
    protected function keyTypeOf(string $model): ?IdConstraint
    {
        if (! class_exists($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        $uses = class_uses_recursive($model);

        if (in_array(HasUuids::class, $uses, true)) {
            return IdConstraint::Uuid;
        }

        if (in_array(HasUlids::class, $uses, true)) {
            return IdConstraint::Ulid;
        }

        try {
            $instance = new $model;
        } catch (Throwable) {
            // A model whose constructor needs arguments is not one this audit can interrogate. Say
            // nothing rather than guess — see the `null` branch in run().
            return null;
        }

        if ($instance->getIncrementing() && $instance->getKeyType() === 'int') {
            return IdConstraint::Int;
        }

        return null;
    }
}
