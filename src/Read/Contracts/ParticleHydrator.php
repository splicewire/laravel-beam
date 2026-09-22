<?php

namespace Splicewire\Beam\Read\Contracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The read seam that mirrors the write pipeline's {@see ParticleWriter}
 * (beam-write-pipeline ticket 13, DESIGN §9). Where the writer collapses validate→authorize→persist→emit,
 * the hydrator collapses resolve→(reconcile)→assemble a typed `Data` from scattered sources — and
 * compiles ONE {@see ReadContext::$includes} list into BOTH the eager-load axis AND the serialization
 * partial, killing the double-declaration.
 *
 * List query composition belongs to ParticleListQuery; this port assembles and projects records.
 *
 * A host still swaps the whole port (port-in-base / binding-in-host, DESIGN §9d) when it has a genuinely
 * different read concern to compose — `~/Herd/splicewire-app` binds beam's own `SourcedParticleHydrator`
 * router to send FOREIGN refs to a federated arm. That is composition over the port, not a contest with
 * the default.
 */
interface ParticleHydrator
{
    /**
     * Assemble a single typed `Data` for `$source` under `$ctx` — the detail read, and the degenerate
     * reader's whole job. `$source` is a persisted model, a raw payload array, or a record id/ref.
     */
    public function hydrate(Model|array|string $source, ReadContext $ctx): Data;

    /**
     * The shared row-mapper both the detail read and each list row call: project one loaded record to a
     * typed `Data`, applying `$ctx->includes` as the serialization partial.
     */
    public function project(Model $record, ReadContext $ctx): Data;
}
