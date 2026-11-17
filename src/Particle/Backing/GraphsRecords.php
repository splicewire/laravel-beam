<?php

namespace Splicewire\Beam\Particle\Backing;

use Rushing\Graphine\Contracts\GraphStore;

/**
 * Capability: this backing's records form a GRAPH, and it can hand back a graphine {@see GraphStore}
 * already scoped to the actor.
 *
 * The port is the first half of D2 (PLAN.md, ruled 2026-11-12): a resource opts a graph in through a
 * backing capability, beside {@see QueriesRecords}, {@see StreamsRecords} and {@see WritesRecords}.
 * The second half — the `graph:` slot on the declaration that lets an Eloquent-backed resource spell
 * the edge source without writing a backing — is ticket 04 and is deliberately NOT here.
 *
 * `graph()` takes the SAME filter bag every capability takes (see {@see StreamsRecords::records()} and
 * {@see QueriesRecords}), so the store it returns is already narrowed to the rows the actor may read:
 * authorization happens once, on the scoped query, and the traversal runs inside that scope rather
 * than re-deriving it. The return is graphine's own small entry seam (`GraphStore`: identity +
 * capability introspection, with the four role sub-contracts hanging off it); beam adds no traversal
 * of its own here (nothing mounts in this ticket — tickets 02/03).
 */
interface GraphsRecords extends ResourceBacking
{
    /**
     * A graphine store over this backing's records, scoped to the actor by the filter bag.
     *
     * @param  array<string, mixed>  $filters  the same filter bag {@see StreamsRecords::records()} takes
     */
    public function graph(array $filters): GraphStore;
}
