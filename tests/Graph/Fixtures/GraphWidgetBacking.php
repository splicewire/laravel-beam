<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Rushing\Graphine\Contracts\GraphStore;
use Rushing\Graphine\Drivers\InMemoryDriver;
use Rushing\Graphine\Dto\Edge;
use Rushing\Graphine\Dto\Node;
use Rushing\Graphine\Dto\NodeId;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\GraphsRecords;

/**
 * The fixture backing — implements the {@see GraphsRecords} port BY HAND (ticket 02 is explicit that the
 * `graph:` slot for model-backed resources is ticket 04). It extends {@see EloquentBacking} so the fixture
 * resource is an ordinary model-backed one; the record-anchored ops do NOT resolve a record through it —
 * their subject is {@see \Splicewire\Beam\Graph\GraphRecordAnchor} (coordinate only) and visibility is the
 * scoped store's `getNode()`. `graph()` builds a small graph with the reference {@see InMemoryDriver},
 * which would otherwise come from the backing's own scoped store (`$filters` is where that scoping rides —
 * a `hidden` id stands in for a row the actor cannot list).
 *
 * The graph: nodes 1–5; `1→2→3→1` is a cycle and `3→4→5` a tail, so there is a path (1→5), a cycle, and
 * a slice to read.
 */
class GraphWidgetBacking extends EloquentBacking implements GraphsRecords
{
    public function __construct()
    {
        parent::__construct(GraphWidget::class);
    }

    public function graph(array $filters): GraphStore
    {
        // The actor's row scope, simulated: a `hidden` filter stands in for rows the actor cannot list, so
        // a hidden id is not a node and no edge touches it — the scoped store a real backing would hand back.
        $hidden = array_map('strval', (array) ($filters['hidden'] ?? []));
        $visible = fn (int|string $id): bool => ! in_array((string) $id, $hidden, true);

        $store = new InMemoryDriver;

        foreach (range(1, 5) as $id) {
            if ($visible($id)) {
                $store->putNode(new Node(new NodeId((string) $id), 'widget', ['label' => "W{$id}"]));
            }
        }

        foreach ([[1, 2], [2, 3], [3, 1], [3, 4], [4, 5]] as [$from, $to]) {
            if ($visible($from) && $visible($to)) {
                $store->putEdge(new Edge(new NodeId((string) $from), new NodeId((string) $to), 'LINKS'));
            }
        }

        return $store;
    }
}
