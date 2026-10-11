<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\ParticleResource;

/** Keeps model readers intact and adds operator authority only through this request's explicit operator-realm mount. */
class ModelOrOperatorReadPolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        $gate = Gate::forUser($actor);
        $model = $resource->modelClass();

        if ($model !== null && $gate->allows('viewAny', $model)) {
            return Response::allow();
        }

        $realm = $request->route()?->defaults['realm'] ?? null;
        if ($realm === 'operator' && $gate->allows('entitlement:os.operate')) {
            return Response::allow();
        }

        return Response::deny("Reading [{$resource->key}] requires its model authority or the operator realm entitlement.");
    }
}
