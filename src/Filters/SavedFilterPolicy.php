<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\DataFilters\SavedFilters\SavedFilter;

class SavedFilterPolicy
{
    /**
     * Any signed-in actor may list saved filters, deliberately: `SavedFilterResourceHandler` serves only the caller's own,
     * shared or public filters and authorizes the target resource first, so the rows are the boundary. Declared so the
     * list read asks it instead of passing on its absence (launch security follow-on to row 51a71469).
     */
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

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
