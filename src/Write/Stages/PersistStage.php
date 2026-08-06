<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Write\Contracts\WriteStage;
use Splicewire\Beam\Write\WriteContext;

/**
 * Stage 3 of the write chain (DESIGN §3a): persist through the model's schema-payload seam when it has one
 * (a {@see PersistsBeamParticle} model decides how content lands on it); otherwise fall back to a plain
 * mass-fill, so the pipeline stays usable by ANY model — e.g. Frame's arbitrary host records (ticket 06).
 *
 * The optional after-persist hook (relation syncs and other record-specific writes) runs HERE, on the saved
 * model — deliberately folded INTO persist rather than promoted to its own stage (DESIGN §3a): it is part of
 * one atomic "the record and its immediate relations landed", not a composable cross-cutting concern.
 */
class PersistStage implements WriteStage
{
    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $model = $context->model;

        if (method_exists($model, 'fillFromSchemaPayload')) {
            $model->fillFromSchemaPayload($context->payload);
        } else {
            $model->fill($context->payload);
        }
        $model->save();

        if ($context->after !== null) {
            ($context->after)($model);
        }

        return $next($context);
    }
}
