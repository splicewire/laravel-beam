<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A bare model with NO graph backing — the negative control: it derives none of the four operations. */
class PlainWidget extends Model
{
    protected $table = 'graph_widgets';

    public $timestamps = false;

    protected $guarded = [];
}
