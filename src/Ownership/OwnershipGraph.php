<?php

namespace Splicewire\Beam\Ownership;

use Splicewire\Beam\Ownership\Contracts\OwnershipEdgeStore;

/**
 * The ownership / GC graph operations (sourced-particles ticket 08, ADR-0161 Position 3 + MAP §Graphine).
 *
 * ============================================================================================
 * THIS IS NOT AUDIT-LINEAGE. Audit-lineage (`Lineage`, tower-core; `LineageRecorder`,
 * `LineageProjector`) is a durability-first derivation LOG that SURVIVES producer deletion and
 * cascades NOWHERE. This graph is its inverse: LIVE, transitive, cascade-on-evict, refcounted. The two
 * provenance axes are orthogonal and MUST NOT be conflated or overloaded. This code never touches Lineage.
 * ============================================================================================
 *
 * Two edge types ({@see OwnershipEdgeType}):
 *  - `owns`/`derived` → cascade-eligible + refcounted.
 *  - `references`     → link to a pre-existing locally-owned entity; NEVER cascades.
 *
 * The LIVE-CTE split (documented per the MAP): the GC CASCADE walks the `owns` subtree via a LIVE
 * recursive CTE ({@see OwnershipEdgeStore::ownedSubtree()} in the Eloquent store — the staudenmeir/silo
 * shape), NOT graphine's snapshot `RelationalDriver` (snapshot-only; a stale snapshot would mis-GC).
 * graphine's contract MAY later back read/nav/rank behind a SEPARATE adapter; a live graphine relational
 * driver that would unify the two is a known OPEN thread, out of scope. Cascade = live CTE, always.
 *
 * PLACEMENT: beam-core. `beam → graphine` is a legal downward edge (rushing/*, portfolio-clean) and the
 * graph is about particles (`beam_particles`), so beam is the natural home — but the SHIPPING cascade
 * takes NO graphine dependency (it is a live CTE over beam's own table), so beam's composer needn't add
 * graphine now (also sidestepping graphine's PHP ^8.3 floor vs beam's ^8.2). The graphine-read adapter is
 * the deferred seam.
 */
class OwnershipGraph
{
    /**
     * The materialize (pin) recursion depth guard — mirrors the federation delegation guard's depth≤3
     * intent. record()'s cycle+depth check fires HERE, on the write/materialize path, not only on retrieve.
     */
    public const MATERIALIZE_DEPTH_LIMIT = 3;

    public function __construct(private OwnershipEdgeStore $edges) {}

    /**
     * Record one ownership edge at materialize (ticket 07 calls this as it links each derived/referenced
     * entity). Idempotent. For an `owns` edge, the MATERIALIZE recursion is guarded BEFORE the edge lands:
     *  - CYCLE: refuse if $target already `owns`-reaches $owner (would make the cascade non-terminating).
     *  - DEPTH: refuse if the resulting owned chain would exceed {@see self::MATERIALIZE_DEPTH_LIMIT}.
     * `references` edges are never cascade-bearing, so they skip the guard (they cannot cycle the GC walk).
     *
     * @throws OwnershipCycleDetected the edge would introduce a cycle or breach the depth guard
     */
    public function record(string $owner, string $target, OwnershipEdgeType $type): void
    {
        if ($owner === $target) {
            throw OwnershipCycleDetected::edge($owner, $target);
        }

        if ($type === OwnershipEdgeType::Owns) {
            // Cycle: target must not already reach back to owner along `owns` edges.
            if ($this->edges->ownsReaches($target, $owner)) {
                throw OwnershipCycleDetected::edge($owner, $target);
            }

            // Depth: the deepest owned chain THROUGH this new edge = (chain above owner) + this hop +
            // (subtree below target). Guard the materialize recursion so a source can't drive it unbounded.
            $depthThrough = $this->depthAbove($owner) + 1 + $this->depthBelow($target);
            if ($depthThrough > self::MATERIALIZE_DEPTH_LIMIT) {
                throw OwnershipCycleDetected::depth($owner, $target, self::MATERIALIZE_DEPTH_LIMIT);
            }
        }

        $this->edges->insert($owner, $target, $type);
    }

    /**
     * Evict a node and CASCADE its owned subtree, refcounted. The owned subtree is the LIVE `owns`-only
     * recursive walk from $node (via the CTE). A candidate in that subtree is GC'd (deleted) only when its
     * in-degree from `owns` owners OUTSIDE the evicted set hits 0 — i.e. no surviving owner still owns it.
     * A `references` target is never in the owned subtree, so it always survives. Returns the ids deleted.
     *
     * @return array<int, string> the node ids actually GC'd (the evict root + cascaded owned descendants)
     */
    public function evict(string $node): array
    {
        // The full candidate set = the evict root plus everything it transitively OWNS (live walk).
        $subtree = $this->edges->ownedSubtree([$node]);
        $evicting = array_values(array_unique(array_merge([$node], $subtree)));
        $evictingSet = array_flip($evicting);

        // Refcount pass: a candidate survives if some `owns` owner OUTSIDE the evicting set still owns it.
        // The root itself is always deleted (it is what is being evicted); only its descendants are refcounted.
        $deleted = [];
        foreach ($evicting as $candidate) {
            if ($candidate !== $node && $this->hasSurvivingOwner($candidate, $evictingSet)) {
                continue; // another live owner keeps it alive — do not GC.
            }
            $deleted[] = $candidate;
        }

        // Delete the doomed nodes and detach their edges. Deleting the node's row is the GC act; we also
        // drop dangling edges FROM deleted nodes (their targets refcount-survive independently).
        foreach ($deleted as $dead) {
            foreach (array_keys($this->edges->outEdges($dead)) as $target) {
                $this->edges->remove($dead, $target, OwnershipEdgeType::Owns);
                $this->edges->remove($dead, $target, OwnershipEdgeType::References);
            }
            $this->edges->deleteNode($dead);
        }

        return $deleted;
    }

    /**
     * Re-derive $node's owned subtree for a new revision, DIFFING against the current edge set to avoid
     * edge/embedding thrash (MAP acceptance: "diffing to avoid embedding/edge thrash"). $desired is the
     * new direct edge set [target => type] the materialize computed at $newRevision. Only the DELTA is
     * applied: added edges are recorded (guarded), removed OWNED edges cascade-evict their now-orphaned
     * targets, unchanged edges are left ALONE (no churn → no re-embed). References removed are just detached.
     *
     * @param  array<string, OwnershipEdgeType>  $desired  target => type the new revision derives
     * @return array{added: array<int,string>, removed: array<int,string>, kept: array<int,string>}
     */
    public function refresh(string $node, string $oldRevision, string $newRevision, array $desired): array
    {
        $current = $this->edges->outEdges($node);

        $added = [];
        $removed = [];
        $kept = [];

        // Removed / changed: an edge present now but not desired-as-is is detached; if it was an `owns`
        // edge, its target may now be orphaned → cascade-evict it (refcount still protects shared targets).
        foreach ($current as $target => $type) {
            $desiredType = $desired[$target] ?? null;
            if ($desiredType === $type) {
                $kept[] = $target;

                continue;
            }

            $this->edges->remove($node, $target, $type);
            $removed[] = $target;

            if ($type === OwnershipEdgeType::Owns) {
                // The target lost THIS owner. Unlike evict() (which force-deletes its ROOT), a refresh-drop
                // GCs the now-orphaned target ONLY if no OTHER owner survives — a shared target stays alive.
                $this->gcIfOrphaned($target);
            }
        }

        // Added: edges desired but not already present (or with a changed type) — record with the guard.
        foreach ($desired as $target => $type) {
            $currentType = $current[$target] ?? null;
            if ($currentType === $type) {
                continue; // unchanged — already counted as kept.
            }

            $this->record($node, $target, $type);
            $added[] = $target;
        }

        return ['added' => $added, 'removed' => $removed, 'kept' => $kept];
    }

    /**
     * Promote $node from cached→pinned by RE-PARENTING its edges appropriately (the cached→pinned
     * lifecycle, MAP §Decisions 6–7). A cached shadow's foreign-only associations are held loosely; on
     * promotion they graduate to real owned relationships. Concretely: every `references` edge from $node
     * that points at a node ALSO minted under this promotion (in $mintedOwned) is re-parented to an `owns`
     * edge — the promotion now OWNS what it previously only referenced. Pre-existing local references
     * (targets NOT in $mintedOwned) stay `references` (promoting them to owns would wrongly claim a
     * tenant-owned entity for cascade). Returns the re-parented target ids.
     *
     * @param  array<int, string>  $mintedOwned  targets this promotion minted as owned entities
     * @return array<int, string> the target ids whose edge was re-parented references→owns
     */
    public function promote(string $node, array $mintedOwned = []): array
    {
        $mintedSet = array_flip($mintedOwned);
        $reparented = [];

        foreach ($this->edges->outEdges($node) as $target => $type) {
            if ($type !== OwnershipEdgeType::References) {
                continue;
            }
            if (! isset($mintedSet[$target])) {
                continue; // a pre-existing local reference — leave it a reference (never claim it).
            }

            $this->edges->remove($node, $target, OwnershipEdgeType::References);
            $this->record($node, $target, OwnershipEdgeType::Owns);
            $reparented[] = $target;
        }

        return $reparented;
    }

    /**
     * GC an owned target that just lost an owner, ONLY if it is now fully orphaned (no `owns` owner
     * remains). If orphaned, evict() it as its own root — which cascades ITS owned subtree, refcounted.
     * A still-owned (shared) target is left untouched: no thrash. This is the refresh/detach path — the
     * difference from evict() is that the target is NOT force-deleted; its live refcount decides.
     */
    private function gcIfOrphaned(string $target): void
    {
        if ($this->edges->owningNodesOf($target) === []) {
            $this->evict($target);
        }
    }

    /**
     * True if $target still has an `owns` owner OUTSIDE the set being evicted (the refcount survival test).
     *
     * @param  array<string, int>  $evictingSet  flip of the ids being evicted
     */
    private function hasSurvivingOwner(string $target, array $evictingSet): bool
    {
        foreach ($this->edges->owningNodesOf($target) as $owner) {
            if (! isset($evictingSet[$owner])) {
                return true;
            }
        }

        return false;
    }

    /** The longest `owns` chain ABOVE $node (how deep it already sits as an owned target). */
    private function depthAbove(string $node): int
    {
        $max = 0;
        foreach ($this->edges->owningNodesOf($node) as $owner) {
            $max = max($max, 1 + $this->depthAbove($owner));
        }

        return $max;
    }

    /** The longest `owns` chain BELOW $node (the size of the subtree it already owns). */
    private function depthBelow(string $node): int
    {
        // ownedSubtree is a set, not a depth — so measure depth by the store's forward walk directly.
        $max = 0;
        foreach ($this->edges->outEdges($node) as $target => $type) {
            if ($type !== OwnershipEdgeType::Owns) {
                continue;
            }
            $max = max($max, 1 + $this->depthBelow($target));
        }

        return $max;
    }
}
