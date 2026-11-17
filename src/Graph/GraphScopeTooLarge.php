<?php

namespace Splicewire\Beam\Graph;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Splicewire\Beam\Graph\Data\GraphRefusalData;

/**
 * The scoped set a `graph:` slot would load is over `beam.core.graph.max_nodes` (graph-operations 04, D5).
 *
 * {@see EloquentGraphBacking::graph()} counts the scoped rows before it loads one and throws this when the
 * count is over the ceiling. It renders as ticket 02's declared refusal, the 422 {@see GraphRefusalData}
 * `over_limit` body naming `nodes`, so the four operations refuse it exactly as they refuse their own
 * over-limit requests. The set is refused, never truncated. The owner accepted this ceiling for the first
 * version; a traverse-native driver stays nominated.
 */
final class GraphScopeTooLarge extends RuntimeException
{
    public function __construct(public readonly int $max, public readonly int $requested)
    {
        parent::__construct(sprintf('The scoped graph has %d nodes, over the configured ceiling of %d (beam.core.graph.max_nodes).', $requested, $max));
    }

    public function refusal(): GraphRefusalData
    {
        return GraphRefusalData::overLimit('nodes', $this->max, $this->requested);
    }

    /** Laravel renders an exception that has a render() method through it. */
    public function render(): JsonResponse
    {
        return response()->json($this->refusal()->toArray(), 422);
    }
}
