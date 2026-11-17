<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * The cycles found in the graph — the `graph.cycles` operation's output.
 *
 * Each cycle is a {@see GraphPathData} whose last node closes back onto its first; `cycles` is the
 * (limited) list of them, empty when the graph is acyclic. References only (D4).
 */
class GraphCyclesData extends Data
{
    /**
     * @param  list<GraphPathData>  $cycles
     */
    public function __construct(
        public array $cycles,
    ) {}
}
