<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A plain model with a `parent_id` tree, a nullable `name` and a `team` the row-scope tests narrow on (graph-operations 04). */
class GraphTreeNode extends Model
{
    protected $table = 'graph_tree_nodes';

    public $timestamps = false;

    protected $guarded = [];
}
