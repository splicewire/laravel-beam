<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent model the fixture backing wraps. The record-anchored ops do NOT look a record up through it
 * (their subject is {@see \Splicewire\Beam\Graph\GraphRecordAnchor}, which only yields the `{id}` coordinate;
 * visibility is the scoped store's `getNode()`); the model exists only so the backing is an ordinary
 * `EloquentBacking` and the `graph_widgets` rows have somewhere to live.
 */
class GraphWidget extends Model
{
    protected $table = 'graph_widgets';

    public $timestamps = false;

    protected $guarded = [];
}
