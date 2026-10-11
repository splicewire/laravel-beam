<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Keeps model readers intact and adds operator authority only through an operator mount. */
class ModelOrOperatorReadPolicy implements ResourceReadPolicy
{
    public function __construct(private ParticleResourceRegistry $resources) {}

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
        $sharedOperatorSocket = $realm === null
            && FrameSocketRoute::serves($request)
            && in_array('operator', $this->resources->realmsFor($resource->key), true);

        if (($realm === 'operator' || $sharedOperatorSocket) && $gate->allows('entitlement:os.operate')) {
            return Response::allow();
        }

        return Response::deny("Reading [{$resource->key}] requires its model authority or the operator realm entitlement.");
    }
}
