<?php

namespace Splicewire\Beam\Authorization;

/**
 * The ACTOR port — the one seam through which a transport tells the authorization layer who is acting.
 *
 * It exists because "the current user" is not a transport-neutral idea. HTTP has an ambient
 * authenticated user hanging off the request; MCP over stdio has none at all, and the live host fakes
 * one by looking up an address from an environment variable at server boot. An {@see AbilityResolver}
 * that reached for `Auth::user()` directly would therefore silently answer a *different* question on
 * each transport — which is exactly the divergence a single resolver is meant to remove.
 *
 * So the resolver never resolves the actor; it is HANDED one. Each transport supplies the actor through
 * this port, and this interface's implementations are the only place ambient authentication is read.
 *
 * {@see GuardActorPort} is the default binding (the Laravel guard). A transport with no guard binds its
 * own — that is the whole point of the port.
 */
interface ActorPort
{
    /**
     * The principal currently acting, or null when there is none.
     *
     * Null is a legitimate answer, not a failure: the entitlement plane is actor-optional (its gate
     * abilities are declared `fn ($user = null)` so a tenant/credential principal resolves inside the
     * entitlement resolver), and a guest on the per-action plane is simply denied.
     */
    public function actor(): mixed;
}
