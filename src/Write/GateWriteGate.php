<?php

namespace Splicewire\Beam\Write;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * Beam-core's DEFAULT {@see WriteGate}: delegate to the Laravel authorization gate so existing
 * `authorize('create'|'update', ...)` policies keep governing authenticated writes untouched
 * (beam-write-pipeline ticket 03, DESIGN §3a).
 *
 * **Deny-by-default is inherited, not bolted on.** Laravel's gate returns false for any ability with
 * no matching policy method or `Gate::define()` — so a record type nothing has declared a policy for is
 * refused with no extra logic. That is exactly the load-bearing default the port demands (DESIGN §7 L5):
 * the absence of a rule denies.
 *
 * The ability is chosen the way a CRUD controller would: an existing model is an `update`, a fresh one
 * a `create`. A bare schema-stem subject (no model to resolve create-vs-update from) is checked under
 * `create` — but the public-intake world does not use THIS binding; it binds a permissive gate + a
 * schema allow-list (ticket 04). Here, a stem with no policy simply stays denied.
 */
class GateWriteGate implements WriteGate
{
    public function __construct(private Gate $gate) {}

    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
    {
        // An explicit actor scopes the check to them; a null/anonymous actor falls through to the
        // gate's own user resolver (a guest), for which policy methods requiring a user auto-deny.
        $gate = $actor instanceof Authenticatable ? $this->gate->forUser($actor) : $this->gate;

        if ($subject instanceof Model) {
            // An already-persisted record is an update; a fresh instance is a create — the same
            // create/update split a hand-rolled controller makes, so the existing policy applies.
            $ability = $subject->exists ? 'update' : 'create';

            return $gate->allows($ability, $subject);
        }

        // A class-string or bare schema stem: no instance to read existence from, so authorize the
        // creation of that type. Deny-by-default holds — an undeclared type has no policy to allow it.
        return $gate->allows('create', $subject);
    }
}
