<?php

namespace Splicewire\Beam\Collections;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\Stages\AuthorizeStage;
use Splicewire\Beam\Write\Stages\DedupeStage;
use Splicewire\Beam\Write\Stages\EmitStage;
use Splicewire\Beam\Write\Stages\ValidateStage;

/** Compose the shipped ParticleWriter stages; do not create a parallel save path. */
final class CollectionWriter
{
    private ParticleWriter $writer;

    public function __construct(WriteGate $gate, SchemaTargetResolver $targets, AcceptanceGate $acceptance, Dispatcher $events, CollectionPersistStage $persist)
    {
        $this->writer = new ParticleWriter($gate, $targets, $acceptance, $events, [
            new AuthorizeStage($gate), new ValidateStage($targets, $acceptance),
            new DedupeStage($targets), $persist, new EmitStage($events),
        ]);
    }

    public function write(BeamParticle $model, array $payload, mixed $actor, ?Closure $after = null): BeamParticle
    {
        return $this->writer->write($model, $payload, $actor, $after);
    }
}
