<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * A signed-in reader for the happy-path tests: a non-null actor passes the guest gate, and with a
 * permissive `Gate::before` the resource's `viewAny` admits — so the four operations run and their
 * output can be measured. The deny-default gate itself is proven separately (a guest is forbidden).
 */
class GraphReadUser extends User
{
    protected $guarded = [];

    public function getAuthIdentifier()
    {
        return 1;
    }
}
