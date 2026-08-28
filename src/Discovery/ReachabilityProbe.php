<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;

/**
 * A host's answer to *"would this middleware let this caller through?"* — asked WITHOUT running it.
 *
 * The discovery listing is gated per route (41 D6), and a route's gate is whatever middleware it
 * carries. Beam can read the two framework spellings itself (`auth`, `can:`), but `require.admin`,
 * `entitlement:composition-engine` and `consume.engine` are host middleware whose predicates beam has
 * never heard of. It does not guess at them: a host DECLARES a probe per middleware alias in
 * `beam.core.discovery.probes`, and an alias with no probe is treated as reachable rather than
 * as hidden.
 *
 * **Reachable is the honest default for the unknown.** The inverse — hide anything beam cannot prove —
 * would make the listing silently shrink the day a host added a middleware alias, which is exactly the
 * failure mode a runtime listing exists to prevent. An over-listed route is D6's documented caveat; an
 * under-listed one is a lie.
 *
 * Probes must be CHEAP and side-effect free. One listing evaluates every route on its mount — 17 for
 * `open-api-specs` on the flagship — so a probe that queries per call turns one request into seventeen.
 */
interface ReachabilityProbe
{
    /**
     * @param  list<string>  $parameters  The middleware's own arguments, `entitlement:x,y` → `['x','y']`.
     */
    public function allows(?Authenticatable $user, array $parameters, Route $route): bool;
}
