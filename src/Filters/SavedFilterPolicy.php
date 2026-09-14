<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\DataFilters\SavedFilters\SavedFilter;

class SavedFilterPolicy
{
    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, SavedFilter $saved): bool
    {
        // Frame probes a fresh instance for capabilities/missing IDs; the scoped handler owns 404.
        if (! $saved->exists) {
            return true;
        }
        abort_unless($saved->owner_type === $user->getMorphClass() && (string) $saved->owner_id === (string) $user->getAuthIdentifier(), 404);

        return true;
    }

    public function delete(Authenticatable $user, SavedFilter $saved): bool
    {
        return $this->update($user, $saved);
    }
}
