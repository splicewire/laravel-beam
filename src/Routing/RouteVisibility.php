<?php

namespace Splicewire\Beam\Routing;

use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Source\ParticleRouteManifestSource;

/**
 * A route's exposure tier, declared via `Route::get(...)->beam()->visibility(RouteVisibility::Public)`
 * (surgeon-audit-viability ticket 24; moved down from the host's `App\Routing` by api-surface-coherence
 * ticket 15 so beam core can type-hint the macro it now registers). String-backed so the manifest can
 * emit `->value` directly; a future tier (e.g. `Partner`) is a one-line case addition.
 *
 * {@see ParticleRouteManifestSource} excludes Deprecated routes from the generated client;
 * {@see ParticleSlotCollisionAudit} excludes them from its published operation-slot population.
 * This metadata is available to any route owner and does not imply an operation alias.
 */
enum RouteVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Deprecated = 'deprecated';
}
