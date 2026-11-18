<?php

namespace Splicewire\Beam\Graph\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Input to the `graph.cycles` operation: return at most `limit` cycles found in the scoped graph. A
 * negative limit is rejected at validation (422) (graph-operations 02 review N7).
 */
class GraphCyclesInput extends Data
{
    public function __construct(
        #[Description('The most cycles to return, at least 0; the first that many found are returned. It counts cycles, not nodes, so it is not compared to beam.core.graph.max_nodes; the read itself still is: a scoped graph of more than max_nodes nodes (1000 by default) is refused with a 422 naming nodes.')]
        #[Min(0)]
        public int $limit,
    ) {}
}
