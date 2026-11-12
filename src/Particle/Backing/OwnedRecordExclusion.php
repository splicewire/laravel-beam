<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Collections\StandaloneRecordExclusion;

/** A principal-owned declaration is not a standalone schema-authored Collection. */
class OwnedRecordExclusion implements StandaloneRecordExclusion
{
    /** @param Builder<Model> $query */
    public function excludeFrom(Builder $query): void
    {
        $query->whereNull('meta->owner');
    }
}
