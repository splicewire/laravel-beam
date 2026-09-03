<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rushing\DataFilters\Query\ResourceQuery;

/**
 * Ordering only — the exact shape 65 measured on `BeamUxEntryResourceQuery` and `HookResourceQuery`: an
 * overridden `baseQuery()` that narrows nothing. A source read ("does it override?") calls this scoped;
 * the wire read ("does the base carry a predicate?") does not, and the wire read is the honest one.
 */
class BareGadgetQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ($this->definition->requireModel())::query()->orderBy('id');
    }
}
