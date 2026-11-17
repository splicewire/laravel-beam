<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Graph\GraphDirection;

/**
 * Input to the `graph.neighbours` operation: from a record, walk `direction` out to `depth`, at most
 * `limit` nodes. The source record's id is the operation's subject, not a member here.
 */
class GraphNeighboursInput extends Data
{
    public function __construct(
        public GraphDirection $direction,
        public int $depth,
        public int $limit,
    ) {}
}
