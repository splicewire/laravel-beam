<?php

namespace Splicewire\Beam\Graph\Edges;

use Illuminate\Database\Eloquent\Model;
use Rushing\Graphine\Dto\Edge;

/**
 * Where a model-backed resource's edges are: the value of the `graph:` slot (graph-operations 04, D2).
 *
 * A resource backed by an Eloquent model declares its edges in one slot, and beam supplies the
 * {@see \Splicewire\Beam\Particle\Backing\GraphsRecords} port for it ({@see \Splicewire\Beam\Graph\EloquentGraphBacking}).
 * There are two spellings, {@see ParentEdge} (a self-referencing column) and {@see EdgeTable} (rows joined
 * by an edge table). Anything else implements the port in its own backing (ticket 01).
 *
 * A declaration never decides WHICH rows are nodes: the backing hands it the rows the resource's list
 * returns (the row scope already applied), and it answers the edges among them. An edge with an end
 * outside that set is not an edge, so no path runs through a row the actor cannot list.
 */
interface GraphEdges
{
    /**
     * The columns of the resource's own table this declaration reads beyond the key: the edge column for
     * {@see ParentEdge}, and the label column when one is declared.
     *
     * @return list<string>
     */
    public function nodeColumns(): array;

    /**
     * The column a node's `label` is read from, or null for the id. A row whose value is null or empty is
     * labelled with its id (the ruling of 2026-11-17: there is no general row-label concept).
     */
    public function labelColumn(): ?string;

    /**
     * The edges among the scoped rows.
     *
     * @param  Model  $model  an instance of the resource's model (its connection and table)
     * @param  iterable<Model>  $rows  the scoped rows, each selected with {@see nodeColumns()}
     * @param  array<string, int|string>  $ids  the scoped rows' keys, keyed by their string form
     * @return list<Edge>
     */
    public function edges(Model $model, iterable $rows, array $ids): array;

    /**
     * Every table and column this declaration names, for the doctor and the generator's check.
     *
     * @return array<string, list<string>> table => columns
     */
    public function references(Model $model): array;

    /** The declaration as PHP source, for the generator and for messages. */
    public function describe(): string;
}
