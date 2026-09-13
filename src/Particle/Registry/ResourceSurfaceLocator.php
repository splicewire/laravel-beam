<?php

namespace Splicewire\Beam\Particle\Registry;

use Splicewire\Beam\Data\ResourceRegistry\ResourceSurfaceData;

/**
 * Where a registered particle resource is SERVED on this host — one {@see ResourceSurfaceData} per realm
 * it belongs to.
 *
 * ## Why a port
 *
 * Beam owns the realm half of the answer (membership, whether a realm is central, whether it needs a
 * tenant) and reads it in {@see RealmResourceSurfaceLocator}. The other half — which route leaf a realm
 * emits for a key, at what URL, and whether that realm's navigation seats it — is the host's IA: its route
 * plan and its navigation registrations. "The list is host code; the projection is package code."
 * (`Splicewire\Beam\Ux\Frame\RouteContextProjector`.) A host that binds nothing keeps beam's default,
 * whose host fields are null — "no surface known", which a reader must not confuse with "no surface".
 */
interface ResourceSurfaceLocator
{
    /**
     * @return list<ResourceSurfaceData> one per realm the key belongs to, in membership order; empty for a
     *                                   resource homed in no realm
     */
    public function surfacesFor(string $key, ?string $section): array;
}
