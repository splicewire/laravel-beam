<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;

/** Uses the resource's declared realm membership and hard realm entitlement as its read authority. */
class RealmEntitlementReadPolicy implements ResourceReadPolicy
{
    public function __construct(private RealmEntitlementResourceGate $realms) {}

    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        $routeRealm = $request->route('realm');
        $mountedRealm = is_string($routeRealm) && $routeRealm !== '' ? $routeRealm : null;

        return $this->realms->entitledThroughRealm($resource->key, Gate::forUser($actor), $mountedRealm)
            ? Response::allow()
            : Response::deny("Reading [{$resource->key}] requires its declared realm entitlement.");
    }
}
