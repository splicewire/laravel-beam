<?php

namespace Splicewire\Beam\Storage;

/**
 * A {@see StorageDriver} that can be bound to a named database connection. {@see onConnection()} returns
 * a copy that reads, writes and deletes on that connection; the original is unchanged, and the process
 * default connection is never touched — so concurrent work (Octane, fibers) cannot observe one owner's
 * connection through another's driver.
 *
 * It exists for an owner that lives on a connection other than the default: `EntryPromoter` binds a
 * central particle to an `on('central')` entry while the default is a tenant's, and that entry's body has
 * to be found and deleted on `central`.
 */
interface ConnectionAwareStorageDriver extends StorageDriver
{
    /** A copy of this driver bound to `$connection` (null: the default connection). */
    public function onConnection(?string $connection): static;
}
