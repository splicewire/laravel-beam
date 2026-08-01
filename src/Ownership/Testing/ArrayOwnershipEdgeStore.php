<?php

namespace Splicewire\Beam\Ownership\Testing;

use Splicewire\Beam\Ownership\Contracts\OwnershipEdgeStore;
use Splicewire\Beam\Ownership\EloquentOwnershipEdgeStore;
use Splicewire\Beam\Ownership\OwnershipEdgeType;
use Splicewire\Beam\Ownership\OwnershipGraph;

/**
 * A framework-free, in-memory twin of the ownership edge store (sourced-particles ticket 08).
 *
 * It replicates the SAME walks the shipping {@see EloquentOwnershipEdgeStore}
 * does — the `owns`-only subtree BFS, the reverse owner probe, the forward reachability — in plain PHP,
 * so the cascade / refcount / cycle LOGIC in {@see OwnershipGraph} is testable
 * without booting a database (beam's testbench is mid-refactor-broken). The live recursive-CTE SQL is
 * verified separately against a sqlite capsule; this twin verifies the graph algorithm.
 *
 * Lives under `src/Ownership/Testing/` (not `tests/`) so it is autoloadable as production PSR-4 and can
 * back a host's own graph tests too — the same place graphine ships its `ConformsToGraphStore` kit.
 */
class ArrayOwnershipEdgeStore implements OwnershipEdgeStore
{
    /** @var array<int, array{owner: string, target: string, type: OwnershipEdgeType}> */
    private array $edges = [];

    /** @var array<string, true> nodes considered to exist (deleteNode removes them). */
    private array $nodes = [];

    public function insert(string $owner, string $target, OwnershipEdgeType $type): void
    {
        $this->nodes[$owner] = true;
        $this->nodes[$target] = true;

        foreach ($this->edges as $edge) {
            if ($edge['owner'] === $owner && $edge['target'] === $target && $edge['type'] === $type) {
                return; // idempotent.
            }
        }

        $this->edges[] = ['owner' => $owner, 'target' => $target, 'type' => $type];
    }

    public function remove(string $owner, ?string $target, OwnershipEdgeType $type): void
    {
        $this->edges = array_values(array_filter($this->edges, function ($edge) use ($owner, $target, $type) {
            if ($edge['owner'] !== $owner || $edge['type'] !== $type) {
                return true;
            }

            return $target !== null && $edge['target'] !== $target;
        }));
    }

    public function ownedSubtree(array $roots): array
    {
        $seen = [];
        $queue = $roots;

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($this->edges as $edge) {
                if ($edge['owner'] !== $current || $edge['type'] !== OwnershipEdgeType::Owns) {
                    continue;
                }
                $target = $edge['target'];
                if (isset($seen[$target]) || in_array($target, $roots, true)) {
                    continue;
                }
                $seen[$target] = true;
                $queue[] = $target;
            }
        }

        return array_keys($seen);
    }

    public function owningNodesOf(string $target): array
    {
        $owners = [];
        foreach ($this->edges as $edge) {
            if ($edge['target'] === $target && $edge['type'] === OwnershipEdgeType::Owns) {
                $owners[$edge['owner']] = true;
            }
        }

        return array_keys($owners);
    }

    public function ownsReaches(string $from, string $to): bool
    {
        return in_array($to, $this->ownedSubtree([$from]), true);
    }

    public function deleteNode(string $node): void
    {
        unset($this->nodes[$node]);
        $this->edges = array_values(array_filter(
            $this->edges,
            fn ($edge) => $edge['owner'] !== $node && $edge['target'] !== $node
        ));
    }

    public function outEdges(string $owner): array
    {
        $out = [];
        foreach ($this->edges as $edge) {
            if ($edge['owner'] === $owner) {
                $out[$edge['target']] = $edge['type'];
            }
        }

        return $out;
    }

    /** Test helper: does a node still exist? */
    public function has(string $node): bool
    {
        return isset($this->nodes[$node]);
    }

    /** @return array<int, array{owner: string, target: string, type: OwnershipEdgeType}> */
    public function all(): array
    {
        return $this->edges;
    }
}
