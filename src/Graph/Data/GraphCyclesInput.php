<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * Input to the `graph.cycles` operation: return at most `limit` cycles found in the scoped graph.
 */
class GraphCyclesInput extends Data
{
    public function __construct(
        public int $limit,
    ) {}
}
