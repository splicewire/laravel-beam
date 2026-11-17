<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Input to the `graph` operation (a bounded slice): walk out to `depth`, at most `limit` nodes,
 * optionally starting from `roots` (a list of record ids). A null `roots` means the whole scoped graph
 * within the bound, rather than from named starts. A negative depth or a non-positive limit is rejected
 * at validation (422), before the config ceiling (graph-operations 02 review N7).
 */
class GraphSliceInput extends Data
{
    /**
     * @param  list<string>|null  $roots
     */
    public function __construct(
        public ?array $roots,
        #[Min(0)]
        public int $depth,
        #[Min(1)]
        public int $limit,
    ) {}
}
