<?php

namespace Splicewire\Beam\Ownership;

/**
 * The two edge kinds of the ownership / GC graph (sourced-particles ticket 08, ADR-0161 Position 3).
 *
 * The kind decides cascade eligibility — the load-bearing distinction of the whole graph:
 *
 *  - {@see self::Owns} — the owner MADE / derived the target (a materialize DERIVED it). Cascade-eligible
 *    AND refcounted: on evict the target is GC'd only when its in-degree from NON-EVICTED `owns` owners
 *    hits 0. "derived" is the same relation named from the target's side; ONE enum case, not two.
 *  - {@see self::References} — the owner LINKS to a pre-existing, independently locally-owned target
 *    (merge-on-materialize resolve-or-create landed on an existing entity). NEVER cascades: the target
 *    outlives its referrer's eviction unconditionally.
 */
enum OwnershipEdgeType: string
{
    /** The owner derived/created the target: cascade-eligible + refcounted. */
    case Owns = 'owns';

    /** The owner links a pre-existing locally-owned target: never cascades. */
    case References = 'references';

    /** True only for the cascade-eligible kind — the single predicate the cascade CTE filters on. */
    public function cascades(): bool
    {
        return $this === self::Owns;
    }
}
