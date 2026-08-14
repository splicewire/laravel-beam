<?php

namespace Splicewire\Beam\Ownership;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ownership\Contracts\OwnershipEdgeStore;

/**
 * The SHIPPING ownership edge store (sourced-particles ticket 08). Its {@see self::ownedSubtree()} is a
 * LIVE recursive CTE — the staudenmeir/silo-hierarchy shape — NOT graphine's snapshot `RelationalDriver`.
 *
 * WHY LIVE CTE, NOT GRAPHINE (the documented split): graphine's relational driver is SNAPSHOT-only — it
 * hydrates a point-in-time graph and traverses in memory. A GC cascade run against a stale snapshot would
 * mis-decide what to delete (an edge added/removed since the snapshot). The cascade MUST see the graph as
 * it is at eviction time, so it walks the DB live. graphine's contract may still back read/nav/rank behind
 * a separate adapter; a LIVE graphine relational driver that would unify the two is a known OPEN thread.
 *
 * The recursive CTE ({@see self::ownedSubtree()}):
 *
 *     WITH RECURSIVE owned (id) AS (
 *         SELECT target_id FROM beam_ownership_edges WHERE owner_id IN (:roots) AND edge_type = 'owns'
 *         UNION
 *         SELECT e.target_id FROM beam_ownership_edges e
 *             JOIN owned o ON e.owner_id = o.id
 *             WHERE e.edge_type = 'owns'
 *     )
 *     SELECT DISTINCT id FROM owned;
 *
 * `edge_type = 'owns'` filters the walk to cascade-eligible edges — `references` edges are NOT traversed,
 * so a referenced (pre-existing locally-owned) target is never in the owned subtree and always survives.
 * `UNION` (not `UNION ALL`) de-dupes and terminates a DAG; record()'s cycle guard keeps the graph acyclic
 * so the walk cannot loop. Postgres and sqlite both honour this syntax (host adapts on another engine).
 */
class EloquentOwnershipEdgeStore implements OwnershipEdgeStore
{
    public function __construct(private ?ConnectionInterface $connection = null) {}

    public function insert(string $owner, string $target, OwnershipEdgeType $type): void
    {
        // Idempotent on the (owner,target,type) triple (the UNIQUE index). updateOrInsert keeps it a no-op
        // when the edge already exists, so record() at re-materialize does not thrash.
        $this->db()->table($this->table())->updateOrInsert(
            ['owner_id' => $owner, 'target_id' => $target, 'edge_type' => $type->value],
            ['id' => (string) Str::uuid(), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function remove(string $owner, ?string $target, OwnershipEdgeType $type): void
    {
        $query = $this->db()->table($this->table())
            ->where('owner_id', $owner)
            ->where('edge_type', $type->value);

        if ($target !== null) {
            $query->where('target_id', $target);
        }

        $query->delete();
    }

    public function ownedSubtree(array $roots): array
    {
        if ($roots === []) {
            return [];
        }

        $table = $this->table();
        $placeholders = implode(', ', array_fill(0, count($roots), '?'));

        $sql = 'WITH RECURSIVE owned (id) AS ('
            ."SELECT target_id FROM {$table} WHERE owner_id IN ({$placeholders}) AND edge_type = ? "
            .'UNION '
            ."SELECT e.target_id FROM {$table} e JOIN owned o ON e.owner_id = o.id WHERE e.edge_type = ?"
            .') SELECT DISTINCT id FROM owned';

        $bindings = array_merge(array_values($roots), [OwnershipEdgeType::Owns->value, OwnershipEdgeType::Owns->value]);

        $rows = $this->db()->select($sql, $bindings);

        // Roots are excluded from the returned subtree (a root may own itself only via a cycle, which
        // record() forbids; guard anyway).
        $rootSet = array_flip($roots);

        return array_values(array_filter(
            array_map(fn ($row) => (string) $row->id, $rows),
            fn ($id) => ! isset($rootSet[$id])
        ));
    }

    public function owningNodesOf(string $target): array
    {
        return $this->db()->table($this->table())
            ->where('target_id', $target)
            ->where('edge_type', OwnershipEdgeType::Owns->value)
            ->distinct()
            ->pluck('owner_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function ownsReaches(string $from, string $to): bool
    {
        return in_array($to, $this->ownedSubtree([$from]), true);
    }

    public function deleteNode(string $node): void
    {
        // GC the particle row itself. Detaching its edges is the graph service's job (it drops dangling
        // edges before calling this); here we delete the owned resource from the particle store.
        $this->db()->table(Beam::table('particles'))->where('id', $node)->delete();
    }

    public function outEdges(string $owner): array
    {
        $out = [];
        foreach ($this->db()->table($this->table())->where('owner_id', $owner)->get(['target_id', 'edge_type']) as $row) {
            $out[(string) $row->target_id] = OwnershipEdgeType::from($row->edge_type);
        }

        return $out;
    }

    private function table(): string
    {
        return Beam::table('ownership_edges');
    }

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
