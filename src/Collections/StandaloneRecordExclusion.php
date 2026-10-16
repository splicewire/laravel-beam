<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Database\Eloquent\Builder;

/** An optional package contributes its owned-particle exclusion without an upward Beam dependency. */
interface StandaloneRecordExclusion
{
    public function excludeFrom(Builder $query): void;
}
