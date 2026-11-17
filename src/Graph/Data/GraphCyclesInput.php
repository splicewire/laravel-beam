<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Input to the `graph.cycles` operation: return at most `limit` cycles found in the scoped graph. A
 * negative limit is rejected at validation (422) (graph-operations 02 review N7).
 */
class GraphCyclesInput extends Data
{
    public function __construct(
        #[Min(0)]
        public int $limit,
    ) {}
}
