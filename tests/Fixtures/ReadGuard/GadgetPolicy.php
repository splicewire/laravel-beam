<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

/** Any bound policy counts as "policy-bound" for the mount rule; the cascade is not required for that. */
class GadgetPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }
}
