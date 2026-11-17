<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Rushing\Graphine\Contracts\ComputeStore;
use Rushing\Graphine\Contracts\EdgeContract;
use Rushing\Graphine\Contracts\GraphStore;
use Rushing\Graphine\Contracts\NodeContract;
use Rushing\Graphine\Contracts\NodeIdContract;
use Rushing\Graphine\Contracts\PathContract;
use Rushing\Graphine\Contracts\StructureStore;
use Rushing\Graphine\Enums\Capability;
use Rushing\Graphine\Enums\TraversalDirection;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\GraphsRecords;

/**
 * A graph backing whose store has the mandatory Declare+Compute spine but does NOT enumerate (no
 * {@see \Rushing\Graphine\Contracts\EnumerableStore}, `supports(Enumerate)` false) — the traverse-native
 * shape graphine models. It exists to prove the `graph` slice declines WITHOUT `roots` (review N3 /
 * scope 2): there is no anchor to walk from and no snapshot to dump.
 */
class NonEnumerableGraphBacking extends EloquentBacking implements GraphsRecords
{
    public function __construct()
    {
        parent::__construct(GraphWidget::class);
    }

    public function graph(array $filters): GraphStore
    {
        return new NonEnumerableGraphStore;
    }
}

class NonEnumerableGraphStore implements ComputeStore, GraphStore, StructureStore
{
    public function name(): string
    {
        return 'non-enumerable-fixture';
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, [Capability::Declare, Capability::Compute], true);
    }

    /** @return list<\Rushing\Graphine\Enums\QueryFormat> */
    public function speaks(): array
    {
        return [];
    }

    public function putNode(NodeContract $node): void {}

    public function putEdge(EdgeContract $edge): void {}

    public function getNode(NodeIdContract $id): ?NodeContract
    {
        return null;
    }

    /** @return list<NodeContract> */
    public function neighbours(NodeIdContract $of, TraversalDirection $direction = TraversalDirection::Descendants, ?int $maxDepth = null): array
    {
        return [];
    }

    public function shortestPath(NodeIdContract $from, NodeIdContract $to): ?PathContract
    {
        return null;
    }

    /** @return array<string, float> */
    public function rank(): array
    {
        return [];
    }

    /** @return list<PathContract> */
    public function detectCycles(): array
    {
        return [];
    }
}
