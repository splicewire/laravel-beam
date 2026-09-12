<?php

namespace Splicewire\Beam\Write\Stages;

use Closure;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Events\BeamParticlePersisted;
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
 *
 * ## The transaction is what makes that sentence true
 *
 * It was a claim, not a mechanism: the save committed on its own, so a hook that threw — or was refused —
 * left the record standing behind the failure. Measured on beam.test (ux-demo-convergence
 * `G3-BEAM-FRAME-CREATE-WRITE-GATE`, 2026-09-12): the Frame console's create form for `beam-ux-entry`
 * wrote the `beam_ux_entries` row, the hook that mints the entry's body was refused by the write gate, and
 * the operator got a 403 over a record that existed and could never be opened.
 *
 * The transaction covers the save and the hook and nothing else. {@see EmitStage} stays OUTSIDE it on
 * purpose: {@see BeamParticlePersisted} drives adoption side-effects (embedding
 * jobs, indexing, notification) and a listener firing inside an uncommitted transaction is the classic
 * way to dispatch a job for a row a worker cannot yet see.
 */
class PersistStage implements WriteStage
{
    public function handle(WriteContext $context, Closure $next): WriteContext
    {
        $model = $context->model;

        // The connection the record itself lands on, so a tenant-connection model's atom is on ITS
        // connection rather than the host's default one.
        $model->getConnection()->transaction(function () use ($model, $context): void {
            if (method_exists($model, 'fillFromSchemaPayload')) {
                $model->fillFromSchemaPayload($context->payload);
            } else {
                $model->fill($context->payload);
            }
            $model->save();

            if ($context->after !== null) {
                ($context->after)($model);
            }
        });

        return $next($context);
    }
}
