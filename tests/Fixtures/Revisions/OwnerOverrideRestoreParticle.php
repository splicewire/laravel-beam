<?php

namespace Splicewire\Beam\Tests\Fixtures\Revisions;

use Splicewire\Beam\Models\BeamParticle;

/**
 * A BeamParticle subclass that OVERRIDES {@see restoreVersionSnapshot()} — the extension point a
 * consumer customises restore through. It counts its own invocations (so a test can prove the override
 * actually ran on revert) and delegates to the base impl, so the write still lands and the revert
 * recorder still sees a store outcome. If the recorder bypassed the overridable method and called a
 * private worker, this override would never run and the counter would stay at zero.
 */
class OwnerOverrideRestoreParticle extends BeamParticle
{
    /** How many times this subclass's restore override has been entered. */
    public static int $overrideCalls = 0;

    /**
     * Its OWN durable morph alias (registered to this class in the test's morph map), so a revision
     * entry recorded for this subclass resolves BACK to this subclass on revert — not to the base
     * BeamParticle the inherited `beam_particle` alias would rebuild, which would skip the override.
     */
    public function getMorphClass(): string
    {
        return 'owner_override_particle';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        self::$overrideCalls++;

        parent::restoreVersionSnapshot($snapshot);
    }
}
