<?php

namespace Splicewire\Beam\Graph\Edges;

use Illuminate\Database\Eloquent\Model;
use Rushing\Graphine\Dto\Edge;
use Rushing\Graphine\Dto\NodeId;

/**
 * Rows joined by an edge table: `graph: new EdgeTable('silo_links', from: 'from_id', to: 'to_id')`.
 *
 * The edge table is read on the model's own connection, restricted to rows whose BOTH ends are scoped
 * rows, so an edge to a row the actor cannot list is never loaded. An optional `type` column gives each
 * edge its type (else {@see $edgeType}), and an optional `weight` column its weight (else 1.0).
 */
final class EdgeTable implements GraphEdges
{
    /**
     * @param  string  $table  the edge table
     * @param  string  $from  its column holding the source row's key
     * @param  string  $to  its column holding the target row's key
     * @param  string|null  $type  its column holding the edge type; null types every edge {@see $edgeType}
     * @param  string|null  $weight  its column holding a numeric weight; null weighs every edge 1.0
     * @param  string|null  $label  the column ON THE RESOURCE'S TABLE a node's label is read from; null labels with the id
     * @param  string  $edgeType  the edge type when no `type` column is declared
     */
    public function __construct(
        public readonly string $table,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $type = null,
        public readonly ?string $weight = null,
        public readonly ?string $label = null,
        public readonly string $edgeType = 'LINKS',
    ) {}

    public function nodeColumns(): array
    {
        return $this->label !== null && $this->label !== '' ? [$this->label] : [];
    }

    public function labelColumn(): ?string
    {
        return $this->label;
    }

    public function edges(Model $model, iterable $rows, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $keys = array_values($ids);
        $columns = array_values(array_filter([$this->from, $this->to, $this->type, $this->weight], fn (?string $c) => $c !== null && $c !== ''));

        $edges = [];

        // Both ends inside the scoped set, in the query itself: an out-of-scope row's edges are never read.
        foreach ($model->getConnection()->table($this->table)->whereIn($this->from, $keys)->whereIn($this->to, $keys)->get($columns) as $row) {
            $row = (array) $row;
            $from = (string) $row[$this->from];
            $to = (string) $row[$this->to];

            if (! isset($ids[$from], $ids[$to])) {
                continue;
            }

            $edges[] = new Edge(
                new NodeId($from),
                new NodeId($to),
                $this->type !== null && ($row[$this->type] ?? null) !== null && $row[$this->type] !== '' ? (string) $row[$this->type] : $this->edgeType,
                $this->weight !== null && is_numeric($row[$this->weight] ?? null) ? (float) $row[$this->weight] : 1.0,
            );
        }

        return $edges;
    }

    public function references(Model $model): array
    {
        $references = [
            $this->table => array_values(array_filter([$this->from, $this->to, $this->type, $this->weight], fn (?string $c) => $c !== null && $c !== '')),
        ];

        if ($this->nodeColumns() !== []) {
            $references[$model->getTable()] = $this->nodeColumns();
        }

        return $references;
    }

    public function describe(): string
    {
        $args = [var_export($this->table, true), 'from: '.var_export($this->from, true), 'to: '.var_export($this->to, true)];

        foreach (['type' => $this->type, 'weight' => $this->weight, 'label' => $this->label] as $name => $value) {
            if ($value !== null) {
                $args[] = $name.': '.var_export($value, true);
            }
        }

        if ($this->edgeType !== 'LINKS') {
            $args[] = 'edgeType: '.var_export($this->edgeType, true);
        }

        return 'new \\'.self::class.'('.implode(', ', $args).')';
    }
}
