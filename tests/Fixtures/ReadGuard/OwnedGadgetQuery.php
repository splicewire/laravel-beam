<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Query\ResourceQuery;

/** The audiostud `RanksQuery` shape: an owner predicate in the base, before any user filter runs. */
class OwnedGadgetQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ($this->definition->requireModel())::query()->where('user_id', (string) Auth::id());
    }
}
