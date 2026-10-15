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
    /** Remove the item at `$key`. A missing key is a no-op, not an error. */
    public function delete(string $key): void;
}
