<?php

namespace Splicewire\Beam\Graph\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Input to the `graph` operation (a bounded slice): walk out to `depth`, at most `limit` nodes,
 * optionally starting from `roots` (a list of record ids). A null `roots` means the whole scoped graph
 * within the bound, rather than from named starts. A negative depth or a non-positive limit is rejected
 * at validation (422), before the config ceiling (graph-operations 02 review N7).
 */
class GraphSliceInput extends Data
{
    /**
     * @param  list<string>|null  $roots
     */
    public function __construct(
        #[Description('Record ids to start the slice from; each root is walked descendants-only (down the graph, never up), and is itself in the slice. Omitted, the slice covers the whole scoped graph within the node bound, and a backing that cannot list its whole graph refuses with a 422 asking for roots.')]
        public ?array $roots,
        #[Description('How many edges to walk down from each root, at least 0. Without roots no walk happens and depth has no effect on the result, though it is still checked: past beam.core.graph.max_depth (10 by default) the read is refused with a 422 naming depth.')]
        #[Min(0)]
        public int $depth,
        #[Description('The most nodes to return, at least 1. Past beam.core.graph.max_nodes (1000 by default), or when the slice holds more nodes than this, the read is refused with a 422 rather than truncated.')]
        #[Min(1)]
        public int $limit,
    ) {}
}
