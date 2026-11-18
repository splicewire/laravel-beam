<?php

namespace Splicewire\Beam\Graph\Data;

use Schemastud\DataSchemas\Attributes\Description;
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
        #[Description('Which way to walk from the record: descendants, ancestors or both.')]
        public GraphDirection $direction,
        #[Description('How many edges out to walk, at least 0. Past beam.core.graph.max_depth (10 by default) the read is refused with a 422 naming depth.')]
        #[Min(0)]
        public int $depth,
        #[Description('The most nodes to return, at least 1. Past beam.core.graph.max_nodes (1000 by default), or when the neighbourhood holds more nodes than this, the read is refused with a 422 rather than truncated.')]
        #[Min(1)]
        public int $limit,
    ) {}
}
