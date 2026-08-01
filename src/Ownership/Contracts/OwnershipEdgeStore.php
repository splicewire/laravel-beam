<?php

namespace Splicewire\Beam\Ownership\Contracts;

use Splicewire\Beam\Ownership\EloquentOwnershipEdgeStore;
use Splicewire\Beam\Ownership\OwnershipEdgeType;
use Splicewire\Beam\Ownership\OwnershipGraph;
use Splicewire\Beam\Ownership\Testing\ArrayOwnershipEdgeStore;

/**
 * The persistence seam under {@see OwnershipGraph} (sourced-particles ticket 08).
 *
 * The graph ALGORITHM (cascade / refcount / cycle-guard / diff) lives in the service; this port is the
 * thin data layer it drives. Two implementations:
 *
 *  - {@see EloquentOwnershipEdgeStore} — the SHIPPING store. Its
 *    {@see self::ownedSubtree()} is the LIVE recursive CTE (staudenmeir-style, the silo-hierarchy shape) —
 *    NOT graphine's snapshot driver. graphine's `RelationalDriver` is snapshot-only; the GC cascade needs
 *    a live walk, so the cascade path is deliberately the CTE. (graphine's contract MAY later serve
 *    read/nav/rank behind a separate adapter — that split is documented in the service; a live graphine
 *    relational driver is a known OPEN thread, out of scope here.)
 *  - {@see ArrayOwnershipEdgeStore} — a framework-free in-memory twin
 *    that replicates the same walks in PHP, so the cascade/refcount/cycle LOGIC is testable without a DB
 *    (beam's testbench boot is mid-refactor-broken).
 */
interface OwnershipEdgeStore
{
    /** Record one directed edge (idempotent on the (owner,target,type) triple). */
    public function insert(string $owner, string $target, OwnershipEdgeType $type): void;

    /** Remove a specific edge, or all edges of a type from an owner when $target is null. */
    public function remove(string $owner, ?string $target, OwnershipEdgeType $type): void;

    /**
     * The set of `owns`-reachable descendant node ids of $roots (roots EXCLUDED), via a LIVE walk.
     * The Eloquent store runs this as a recursive CTE; the array store BFS's in PHP. `references`
     * edges are NOT traversed — the owned subtree is `owns`-only.
     *
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    public function ownedSubtree(array $roots): array;

    /**
     * The distinct owner ids that own $target via an `owns` edge (the reverse in-edges). Used for the
     * refcount: a node survives a cascade while ANY of these owners is not itself being evicted.
     *
     * @return array<int, string>
     */
    public function owningNodesOf(string $target): array;

    /**
     * True if $from can reach $to by following `owns` edges FORWARD (the cycle probe for record()):
     * an `owns` edge owner→target is refused when target already owns owner transitively.
     */
    public function ownsReaches(string $from, string $to): bool;

    /** Hard-delete a node's row from the underlying particle store (the GC act). */
    public function deleteNode(string $node): void;

    /**
     * The direct out-edges of $owner as [target => type] pairs (for refresh() diffing).
     *
     * @return array<string, OwnershipEdgeType>
     */
    public function outEdges(string $owner): array;
}
