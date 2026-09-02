<?php

namespace Splicewire\Beam\Particle\Subject;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * The edge's bound PARENT is the subject — a collection-level operation under a relative edge.
 *
 * `POST golden-sets/{goldenSet}/questions/reorder`, `DELETE golden-sets/{goldenSet}/questions`: the
 * operation is declared on the CHILD resource (`questions`), mounted under the parent's edge, carries no
 * coordinate of its own, and both its gate and its response are the parent (particle-operation-surface
 * 16 §D3, measured on 15's scouts: `authorize('update', $scope)`, `GoldenSetData::fromScope(...)`).
 *
 * It is {@see ActorSubject}'s exact sibling one axis over — the actor arrives from the transport, the
 * parent from the edge — which is why it fits the port with no change to it: `pathParameters() === []`,
 * `yieldsSubject() === true`. And it is the case that makes `yieldsSubject()` irreducible to the
 * coordinate list a second time: {@see NoSubject} is also `[]` and yields nothing.
 *
 * ## It reads the edge's route defaults, so a class-string is the ordinary spelling
 *
 * `ParticleMounter::relative()` stamps every route inside an edge with the parent's BINDING NAME
 * (`ParticleController::RELATIVE`) and MODEL (`RELATIVE_MODEL`), and Laravel merges route defaults into
 * the parameter bag the controller hands this resolver. So `subject: ParentSubject::class` needs to be
 * told nothing — it finds the binding off the defaults and the bound parent under that name. The
 * INSTANCE spelling (`new ParentSubject('hull')`) exists for an op mounted inside a hand-written
 * `Route::group(['prefix' => 'hulls/{hull}'])` that carries no edge stamp; there the binding must be
 * named, and the parameter must arrive already substituted (`SubstituteBindings`), because with no
 * `RELATIVE_MODEL` default there is nothing to resolve a raw id against.
 *
 * The raw-id branch mirrors {@see ParticleController::relativeContext()} exactly — a stranger parent id
 * is the same 404 the child's CRUD gives it, never a resolver-shaped error.
 */
class ParentSubject implements ResolvesOperationSubject
{
    /**
     * @param  string|null  $binding  the route parameter holding the bound parent; `null` reads it off
     *                                the edge's `RELATIVE` route default (the class-string spelling)
     */
    public function __construct(
        public ?string $binding = null,
    ) {}

    /**
     * None of its own: the parent's coordinate is the enclosing edge's, already in the group prefix.
     *
     * @return list<string>
     */
    public static function pathParameters(): array
    {
        return [];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        $binding = $this->binding ?? $parameters[ParticleController::RELATIVE] ?? null;

        if (! is_string($binding) || $binding === '') {
            throw new RuntimeException(
                "Particle operation [{$operation->key()}] declares ".self::class.' but is not mounted under a '
                .'relative edge and names no binding. Mount it through `Particle::relatives()` / a '
                ."`#[ParticleRelative]` with `ops:`, or declare it `new ParentSubject('<binding>')`."
            );
        }

        $bound = $parameters[$binding] ?? null;

        if ($bound instanceof Model) {
            return $bound;
        }

        $model = $parameters[ParticleController::RELATIVE_MODEL] ?? null;

        if ($bound === null || ! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new RuntimeException(
                "Particle operation [{$operation->key()}] resolves its parent from `{{$binding}}`, which is "
                .'neither a bound model nor a raw id under a relative edge that names its model.'
            );
        }

        return $model::query()->findOrFail($bound);
    }
}
