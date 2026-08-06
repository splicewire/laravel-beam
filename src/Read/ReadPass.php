<?php

namespace Splicewire\Beam\Read;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * The mutable passable that rides the {@see PayloadParticleReader} stage chain (beam-write-pipeline ticket 13,
 * DESIGN §9a/§9d). It threads the source record and its {@see ReadContext} through the read stages and
 * accumulates the projected {@see Data} as the terminal {@see Stages\ProjectStage} builds it.
 *
 * The reader is "the degenerate Hydrator" — the shipped chain is a SINGLE project stage. The seam exists so a
 * host can compose an intra-projection concern (field redaction, actor-scoped visibility, a computed include)
 * as its own pipe WITHOUT replacing the whole hydrator. The coarser read seam remains the
 * {@see Contracts\ParticleHydrator} port itself (swap the entire reader — e.g. a query-composing
 * `DataFilterRecordHydrator`); this stage seam is the fine-grained one inside the default reader.
 */
class ReadPass
{
    public function __construct(
        public Model $record,
        public ReadContext $ctx,
        public ?Data $data = null,
    ) {}
}
