<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Source\SourceWriteTier;

/** ADR-0235 d3: local standalone particles, never shadows or a contributing package's owned bodies. */
class StandaloneRecordQuery
{
    public function __construct(private Container $container) {}

    public function query(?string $connection = null): Builder
    {
        $query = BeamParticle::on($connection)->where('source_tier', SourceWriteTier::Local->value);
        foreach ($this->container->tagged(StandaloneRecordExclusion::class) as $exclusion) {
            // Intersect, rather than trusting a contributor not to widen the base with an orWhere.
            $excluded = BeamParticle::on($connection);
            $exclusion->excludeFrom($excluded);
            $query->whereIn($query->getModel()->getQualifiedKeyName(), $excluded->select('id'));
        }

        return $query;
    }
}
