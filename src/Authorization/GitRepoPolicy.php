<?php

namespace Splicewire\Beam\Authorization;

use Rushing\PermissionCascade\Contracts\GrantedExplicitly;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Models\GitRepo;

/**
 * The cascade policy for {@see GitRepo}, RESERVED (ux-walkthrough UX-08c): its tokens are not team content, so the uniform
 * role tiering grants them to no role, and a host names them per role in `beam.accounts.roles.grants` (the shape
 * commerce's PlanPolicy and tower's ConduitPolicy set). Until then only a host's Gate::before superuser (Root) reads it.
 * The token names are unchanged.
 */
class GitRepoPolicy extends BaseModelPolicy implements GrantedExplicitly
{
    public static $defaultModelClass = GitRepo::class;
}
