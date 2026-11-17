<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * Input to the `graph.path` operation: find a path from the source record (the operation's subject) to
 * the record identified by `target`.
 */
class GraphPathInput extends Data
{
    public function __construct(
        public string $target,
    ) {}
}
