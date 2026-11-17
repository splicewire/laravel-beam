<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * A bounded slice of the graph: the `graph` operation's output, and the shared sub-graph shape.
 *
 * Just the two reference lists — nodes and the edges between them — within the depth/limit the input
 * asked for. No payloads (D4).
 */
class GraphSliceData extends Data
{
    /**
     * @param  list<GraphNodeData>  $nodes
     * @param  list<GraphEdgeData>  $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
    ) {}
}
