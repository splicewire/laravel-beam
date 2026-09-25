<?php

namespace Splicewire\Beam\Frame;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Contracts\ResourceActionAuthorizer;
use Schemastud\Frame\Registry\ResourceActionDefinition;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\ActorSubject;
use Splicewire\Beam\Particle\Subject\OperationSubjectModel;
use Throwable;

/**
 * Beam's answer to frame's "may this actor press this action?" (ADR-0223) — the SAME question
 * {@see ParticleOperationController::invoke()} asks before the op runs,
 * through the same {@see AbilityResolver}, so a drawn button and the mount's refusal cannot disagree.
 *
 * The controller's rule, restated here only in its subject half:
 *
 *  - `ability: false` is declared-ungated and `null` is undeclared; the mount lets both through, so both
 *    are drawn. (The undeclared residue is counted by `particle.operation-ability`, not re-decided here.)
 *  - a string ability is checked against `abilityModel: false` ⇒ no subject (the entitlement plane);
 *    a class-string `abilityModel` ⇒ that class; otherwise the resolved subject.
 *
 * The resolved subject is where the manifest differs from the mount, and it is the only place:
 *
 *  - a `resource` action with `NoSubject` resolves nothing at the mount either, so the answer is exact;
 *    with `ActorSubject` the subject IS the actor, which this has too;
 *  - a `record` action asked with a record (a detail page's probe) uses that record; asked at class level
 *    (the manifest), it uses a fresh instance of the op's subject model — the same advisory
 *    approximation frame's own write gate makes, safe in the direction that offers a button the mount
 *    then refuses. With no resolvable model at all it answers false.
 *
 * `signed:` is not consulted: a signed link admits an anonymous holder at the mount, and a frame screen
 * has an actor, who is asked about their ability as any caller without a signature would be.
 */
class ParticleActionAuthorizer implements ResourceActionAuthorizer
{
    /**
     * Dependency-free on purpose: beam's provider constructs this at boot to see whether frame's default
     * binding won the discovery-order race, and a transport's {@see ActorPort} need not be resolvable
     * then. Each collaborator is resolved when a question is actually asked.
     */
    public function __construct() {}

    public function allows(ResourceDefinition $definition, ResourceActionDefinition $action, ?Model $record = null): bool
    {
        $operation = app(ParticleOperationRegistry::class)->find($definition->key, $action->key);

        if ($operation === null) {
            return false;
        }

        if (! is_string($operation->ability)) {
            return true;
        }

        $actor = app(ActorPort::class)->actor();

        if ($operation->abilityModel === false) {
            $subject = null;
        } elseif (is_string($operation->abilityModel)) {
            $subject = $operation->abilityModel;
        } elseif ($action->scope === ResourceActionDefinition::ScopeRecord) {
            $subject = $record ?? $this->freshSubject($operation);

            if ($subject === null) {
                return false;
            }
        } else {
            // A `resource` action: NoSubject resolves nothing, ActorSubject resolves the actor.
            $subject = is_object($actor) && $this->yieldsActor($operation) ? $actor : null;
        }

        return app(AbilityResolver::class)->allows($actor, $operation->ability, $subject);
    }

    protected function yieldsActor(ParticleOperation $operation): bool
    {
        $subject = $operation->subject;
        $class = is_object($subject) ? $subject::class : $subject;

        return is_string($class) && is_a($class, ActorSubject::class, true);
    }

    protected function freshSubject(ParticleOperation $operation): ?Model
    {
        try {
            $model = app(OperationSubjectModel::class)->for($operation);
        } catch (Throwable) {
            return null;
        }

        return is_string($model) && is_a($model, Model::class, true) ? new $model : null;
    }
}
