<?php

namespace Splicewire\Beam\Particle\Backing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Versioning\MigrationStatus;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteNotAuthorized;

/** Prepare the envelope and complete payload before canonical validation/persistence. */
class ActorOwnedParticleWriteStage implements WriteStage
{
    public function __construct(private ActorOwnedParticleBacking $backing) {}

    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $model = $context->model;
        if (! $this->backing->hasWritePolicy() || ! $model instanceof BeamParticle || ! $context->actor instanceof Authenticatable
            || $context->actor !== $this->backing->request()->user()
            || ($model->exists && ! ActorOwnedParticleAccess::owns($context->actor, $model))
            || $model->getAttribute('schema_ref') !== $this->backing->binding()
            || ! $this->backing->permitsWrite($model, $context->actor)) {
            throw WriteNotAuthorized::for($model);
        }
        $schema = $this->backing->registerSchema();
        $context->payload = array_replace($model->exists ? $model->getAttribute('payload') : [], $context->payload);
        if (! $model->exists) {
            $meta = $model->getAttribute('meta') ?? [];
            $meta['owner'] = ActorOwnedParticleAccess::owner($context->actor);
            $model->setAttribute('meta', $meta);
        }
        $model->setAttribute('schema_id', $schema['$id']);
        $model->setAttribute('migration_status', MigrationStatus::Current->value);

        return $next($context);
    }
}
