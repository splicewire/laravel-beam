<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * Input to the `graph` operation (a bounded slice): walk out to `depth`, at most `limit` nodes,
 * optionally starting from `roots` (a list of record ids). A null `roots` means the whole scoped graph
 * within the bound, rather than from named starts.
 */
class GraphSliceInput extends Data
{
    /**
     * @param  list<string>|null  $roots
     */
    public function __construct(
        public ?array $roots,
        public int $depth,
        public int $limit,
    ) {}
}
