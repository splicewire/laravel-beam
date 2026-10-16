<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

/** Optional batch enrichment AFTER actor projection; never a replacement for admission or projection. */
interface EnrichesProjectedRecords extends ResourceBacking
{
    /** @param list<Data|array> $records */
    public function enrich(array $records, ?Authenticatable $actor): array;
}
