<?php

namespace Splicewire\Beam\Storage;

/**
 * A {@see StorageDriver} that can also remove what it stored — the fifth verb of the storage seam, kept
 * as its own capability rather than added to the port so a host's or a test's existing driver keeps
 * implementing {@see StorageDriver} unchanged. All three shipped drivers implement it.
 *
 * It exists for the owner of a stored body that is itself going away for good: a force-deleted
 * `BeamUxEntry` used to leave its particle row and its disk-mirror file behind (measured in
 * ux-demo-convergence step 2 — one pair per run), because the port had no way to say "remove".
 */
interface DeletableStorageDriver extends StorageDriver
{
    /**
     * Whether {@see delete()} can remove EVERYTHING this driver writes for a key. A composite answers
     * false when any of its tiers cannot delete; a caller that wants removal checks this first.
     */
    public function canDelete(): bool;

    /**
     * Remove the item at `$key` from everything this driver writes. A missing key is a no-op, not an
     * error. When {@see canDelete()} is false this throws a {@see \LogicException} and removes nothing;
     * when a store refuses the removal at run time it throws a {@see \RuntimeException}. A delete that
     * returns always means full removal, never a partial one.
     */
    public function delete(string $key): void;
}
