<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Models\GitRepo;
use Splicewire\Beam\Particle\ParticleResource;

/** The git-repo projection's read authority, without widening the GitRepo model policy. */
class GitRepoReadPolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        $gate = Gate::forUser($actor);

        return $gate->allows('viewAny', GitRepo::class)
            || $gate->allows('entitlement:os.operate')
                ? Response::allow()
                : Response::deny('Reading [git-repo] requires its model view grant or the operator entitlement.');
    }
}
