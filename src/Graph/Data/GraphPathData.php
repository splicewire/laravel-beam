<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * One path through the graph — the `graph.path` operation's output.
 *
 * The ordered nodes from the source record to the target, and the edges that connect them in order
 * (`edges[i]` runs from `nodes[i]` to `nodes[i+1]`). An empty `nodes` is "no path". References only (D4).
 */
class GraphPathData extends Data
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
