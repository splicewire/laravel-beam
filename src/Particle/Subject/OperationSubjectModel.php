<?php

namespace Splicewire\Beam\Particle\Subject;

use RuntimeException;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleResourceModelResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The ONE answer to *"which Eloquent model does this operation's `{id}` resolve against"*.
 *
 * ## The model is a property of the RESOURCE, not of the operation
 *
 * particle-operation-surface ticket 18's ruling. An op names a `resource:`; the resource names its
 * `backing:`; the model is a fact about that backing. {@see ParticleOperation::$model} said it a
 * second time, per-OPERATION, for a fact that has resource grain — so `market-products` named
 * `MarketProduct` four times with nothing checking the four agreed, and `beam-ux`'s two entry ops
 * named `BeamUxEntry` twice more. That is a duplication with no arbiter, which is the shape this
 * estate has been removing everywhere else.
 *
 * The precedent is shipped, on the resource side and one tier out: {@see ParticleResourceModelResolver}
 * is *"the adapter that lets a `#[ResourceFilter]` omit `model:` and have it resolved from the
 * `ParticleResource` already declared under the same key"*. This class is the identical move for
 * `#[ParticleOp]`, and it reads the same declared fact — the backing's {@see BacksModel::modelClass()}.
 *
 * ## Absence is a return value, never an exception
 *
 * Three callers need this and they disagree about what "unresolvable" means, so the class refuses to
 * decide for them:
 *
 *   - {@see RecordSubject} and {@see ColumnSubject} are at REQUEST time, where nothing can be done but
 *     fail — they raise their own error naming the declaration that is missing;
 *   - `ParticleIdConstraintKeyTypeAudit` is at DOCTOR time, where an unresolvable model is the audit's
 *     own blind spot rather than the host's defect, and it already skips on `null`.
 *
 * The registry is consulted NON-throwingly for the same reason it always was: an operation registered
 * against a key that is not a registered particle resource is an ordinary declaration, not an error.
 *
 * ## The deprecated `$model` is still read, and it is read SECOND
 *
 * While {@see ParticleOperation::$model} exists it is honoured as a fallback, so the estate migrates a
 * declaration site at a time rather than in one act. The order is deliberate: the resource's backing
 * WINS over a declared `$model`, because a disagreement between the two is the duplication defect this
 * ticket exists to remove, and the resource is the side that owns the fact.
 */
class OperationSubjectModel
{
    public function __construct(protected ?ParticleResourceRegistry $resources = null) {}

    /**
     * @return class-string|null the model class, or `null` when neither the resource's backing nor the
     *                           declaration names one
     */
    public function for(ParticleOperation $operation): ?string
    {
        $resources = $this->resources ?? app(ParticleResourceRegistry::class);

        if ($resources->has($operation->resource)) {
            $backing = $resources->get($operation->resource)->backing();

            if ($backing instanceof BacksModel) {
                return $backing->modelClass();
            }
        }

        return $operation->model;
    }

    /**
     * The same read, raising at REQUEST time rather than answering `null` — the two subject resolvers'
     * shape, where there is nothing left to do but say which declaration is missing.
     *
     * @return class-string
     */
    public function require(ParticleOperation $operation): string
    {
        return $this->for($operation) ?? throw new RuntimeException(sprintf(
            'Operation [%s] resolves its `{id}` against a model, and nothing declares one. Its '
            .'`resource:` key [%s] is either unregistered or its backing does not implement %s. '
            .'Declare a `#[ParticleResource]` for that key whose `backing:` names the model.',
            $operation->key(),
            $operation->resource,
            BacksModel::class,
        ));
    }
}
