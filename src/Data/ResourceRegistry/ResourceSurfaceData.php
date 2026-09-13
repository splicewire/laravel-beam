<?php

namespace Splicewire\Beam\Data\ResourceRegistry;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Registry\ResourceSurfaceLocator;

/**
 * One place a resource is served in a HOST: a realm it belongs to, and — where the host emits one — the
 * route leaf that realm's client renders its list at.
 *
 * Only a host can fill most of this. Realm membership and a realm's shape are beam's to read, but which
 * leaf a realm emits, at what URL, and whether a nav seat points at it are host IA
 * ({@see ResourceSurfaceLocator}). A host that binds no locator still gets one row per realm with every
 * host-only field null, which reads as "no surface known" rather than as "no surface".
 */
#[TypeScript]
class ResourceSurfaceData extends BeamData
{
    public function __construct(
        /** The realm key (`operator`, `tenant`, `user`, …). */
        public string $realm,
        /** The realm is central — served without a tenant (`RealmDefinition::$central`). */
        public bool $central,
        /**
         * Reaching this surface's records needs an explicit tenant context: the deployment resolves a
         * tenant for this realm (`TenantResolver::resolvesTenantFor()`). A client must not offer the
         * records of such a surface from a context that has not chosen a tenant.
         */
        public bool $requiresTenant,
        /**
         * The realm is the per-ACTOR realm (the account/identity realm, `RealmRegistry::user()`): its
         * records belong to whoever is signed in, so a surface over it may only ever show the viewer's own.
         */
        public bool $actorScoped,
        /** The list leaf's stable route name in this realm, or null when the realm emits no leaf for it. */
        public ?string $routeName = null,
        /** The client path of that leaf (absolute within the realm's origin), or null. */
        public ?string $href = null,
        /** The single-record twin's route name (`mounts: edit|detail|widget`), or null when none is emitted. */
        public ?string $recordRouteName = null,
        /** The single-record twin's path pattern (e.g. `/operator/plans/:id`), or null. */
        public ?string $recordHref = null,
        /** What the record twin mounts: `edit`, `detail` or `widget`; null when none is emitted. */
        public ?string $recordMounts = null,
        /** A nav seat in this realm's navigation resolves to this leaf. */
        public bool $navSeat = false,
    ) {}
}
