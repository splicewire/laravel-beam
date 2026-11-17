<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Graph\GraphDirection;

/**
 * Input to the `graph.neighbours` operation: from a record, walk `direction` out to `depth`, at most
 * `limit` nodes. The source record's id is the operation's subject, not a member here. A negative depth
 * or a non-positive limit is rejected at validation (422), before the config ceiling is even consulted
 * (graph-operations 02 review N7).
 */
class GraphNeighboursInput extends Data
{
    public function __construct(
        public GraphDirection $direction,
        #[Min(0)]
        public int $depth,
        #[Min(1)]
        public int $limit,
    ) {}
}
