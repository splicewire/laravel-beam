<?php

namespace Splicewire\Beam\Graph\Edges;

use Illuminate\Database\Eloquent\Model;
use Rushing\Graphine\Dto\Edge;
use Rushing\Graphine\Dto\NodeId;

/**
 * A tree, or any graph, by a self-referencing column: `graph: new ParentEdge('parent_id')`.
 *
 * Each row whose column names another scoped row gives one edge, parent → child, typed {@see $type}. The
 * direction is what makes a slice "from roots" read downward: graphine walks Descendants along an edge's
 * direction. A row whose parent is outside the scope keeps its node and loses that edge.
 */
final class ParentEdge implements GraphEdges
{
    /**
     * @param  string  $column  the column on the resource's own table that holds the parent's key
     * @param  string|null  $label  the column a node's label is read from; null labels each node with its id
     * @param  string  $type  the edge type on the wire
     */
    public function __construct(
        public readonly string $column,
        public readonly ?string $label = null,
        public readonly string $type = 'PARENT_OF',
    ) {}

    public function nodeColumns(): array
    {
        return array_values(array_unique(array_filter([$this->column, $this->label], fn (?string $c) => $c !== null && $c !== '')));
    }

    public function labelColumn(): ?string
    {
        return $this->label;
    }

    public function edges(Model $model, iterable $rows, array $ids): array
    {
        $edges = [];

        foreach ($rows as $row) {
            $parent = $row->getAttribute($this->column);

            if ($parent === null || ! isset($ids[(string) $parent])) {
                continue;
            }

            $edges[] = new Edge(new NodeId((string) $parent), new NodeId((string) $row->getKey()), $this->type);
        }

        return $edges;
    }

    public function references(Model $model): array
    {
        return [$model->getTable() => $this->nodeColumns()];
    }

    public function describe(): string
    {
        $args = [var_export($this->column, true)];

        if ($this->label !== null) {
            $args[] = 'label: '.var_export($this->label, true);
        }

        if ($this->type !== 'PARENT_OF') {
            $args[] = 'type: '.var_export($this->type, true);
        }

        return 'new \\'.self::class.'('.implode(', ', $args).')';
    }
}
