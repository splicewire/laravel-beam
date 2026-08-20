<?php

namespace Splicewire\Beam\Routing;

/**
 * A route's exposure tier, declared via `Route::get(...)->beam()->visibility(RouteVisibility::Public)`
 * (surgeon-audit-viability ticket 24; moved down from the host's `App\Routing` by api-surface-coherence
 * ticket 15 so beam core can type-hint the macro it now registers). String-backed so the manifest can
 * emit `->value` directly; a future tier (e.g. `Partner`, `Deprecated`) is a one-line case addition.
 */
enum RouteVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
