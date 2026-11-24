<?php

namespace Splicewire\Beam\Tests\Fixtures\Revisions;

use Splicewire\Beam\Models\BeamParticle;

/**
 * A BeamParticle subclass whose restore override PERSISTS THE RECORD ITSELF and does NOT call parent —
 * the shape of a consumer that owns its own write path on restore. Per the restoreVersionSnapshot()
 * contract, such an override MUST report its save() result through {@see BeamParticle::recordRestoreOutcome()}
 * so the revert recorder can tell a stored write from a vetoed one on a no-op revert (where isDirty()
 * is blind). This fixture reports faithfully, so a vetoed self-save surfaces as a refusal — proving the
 * recordRestoreOutcome() seam carries a reported `false` through the owner branch without the override
 * ever touching parent.
 */
class SelfSavingOverrideRestoreParticle extends BeamParticle
{
    public function getMorphClass(): string
    {
        return 'self_saving_override_particle';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        // Own the write (no parent call), then REPORT its result through the single slot seam.
        $this->recordRestoreOutcome(
            $this->forceFill(['payload' => $snapshot['payload'] ?? null])->save()
        );
    }
}
