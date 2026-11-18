<?php

namespace Splicewire\Beam\Graph\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * Input to the `graph.path` operation: find a path from the source record (the operation's subject) to
 * the record identified by `target`.
 */
class GraphPathInput extends Data
{
    public function __construct(
        #[Description('The id of the record the path ends at. The path starts at the record in the address.')]
        public string $target,
    ) {}
}
