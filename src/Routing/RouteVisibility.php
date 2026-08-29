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
 * ## `Deprecated` is the first tier anything actually READS
 *
 * `Public`/`Internal` shipped with no consumer — `Splicewire\Tower\Routing\RouteManifest:26` says so in
 * as many words (*"Orthogonal to `returns`/codegen; currently has no consumer"*), and a sweep of the
 * estate on 2026-08-29 found **zero** call sites of `->beam()->visibility()` outside beam's own tests
 * and one beam-ux test. So particle-operation-surface 12 could not simply "add a case and rely on the
 * seam this docblock named": there was no seam, only an enum. The tier is added AND its first two
 * readers are added with it — {@see ParticleRouteManifestSource} (keeps the
 * legacy `/op/` alias out of the generated client) and
 * {@see ParticleSlotCollisionAudit} (stops the alias being reported as
 * colliding with the route it is an alias OF).
 *
 * `Deprecated` is a third VALUE on the same axis rather than a boolean beside it: to every reader added
 * here a deprecated route is not additionally public or internal — it *"exists, still answers, is not
 * part of the published surface"*, which is one fact, not two.
 */
enum RouteVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Deprecated = 'deprecated';
}
