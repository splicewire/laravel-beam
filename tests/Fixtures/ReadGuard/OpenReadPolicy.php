<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

/**
 * "Every caller may list this" — declared, so beam-docs-satellite 65's read gate can see it. The fixture
 * tests that bind it exercise ordering, includes and contributions over a central, unscoped model; before
 * 65 they read every row by NOTHING checking, which is the posture the ruling closes. Binding a policy that
 * says the listing is open is the ruling's own repair, and it keeps those suites gate-CLOSED elsewhere
 * (AGENTS.md: a `Gate::before(true)` harness hides defects; a policy that answers one ability does not).
 */
class OpenReadPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }
}
