<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** Principal-scoped rows require an explicit per-resource write policy. */
interface UsesActorOwnership extends BacksModel, QueriesRecords, WritesRecords
{
    public function permitsWrite(Model $model, Authenticatable $actor): bool;
}
