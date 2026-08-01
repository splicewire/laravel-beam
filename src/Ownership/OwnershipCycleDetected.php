<?php

namespace Splicewire\Beam\Ownership;

use RuntimeException;

/**
 * Thrown when the MATERIALIZE (pin) recursion would introduce a cycle into the ownership graph, or
 * would exceed the materialize depth guard (sourced-particles ticket 08 + MAP §Trust/containment —
 * "ensure it fires on the MATERIALIZE recursion, not just retrieve", mirroring the federation
 * delegation guard's depth≤3 / cycle-detect intent).
 *
 * The graph is a DAG by contract: an `owns` edge whose target transitively owns the owner would make
 * the refcounted cascade non-terminating. record() refuses such an edge rather than storing it.
 */
class OwnershipCycleDetected extends RuntimeException
{
    public static function edge(string $owner, string $target): self
    {
        return new self(
            "Refusing ownership edge {$owner} -owns-> {$target}: it would introduce a cycle "
            .'(the target already transitively owns the owner). The ownership graph is a DAG.'
        );
    }

    public static function depth(string $owner, string $target, int $limit): self
    {
        return new self(
            "Refusing ownership edge {$owner} -owns-> {$target}: the materialize recursion would exceed "
            ."the depth guard (limit {$limit}). Deepen deliberately, do not let a source drive it unbounded."
        );
    }
}
