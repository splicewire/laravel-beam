<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Model;

/**
 * Capability: this backing can create, update and delete records.
 *
 * ## This is the CEILING, not the permission
 *
 * `instanceof WritesRecords` is what the backing **can** do. `creatable` / `editable` / `deletable` on
 * the declaration are what this resource **may** do, and they can only narrow. An affordance set true
 * against a backing lacking this capability is a declaration error and throws at registration
 * ({@see ResourceBacking}) — the shape `ParticleOperation::assertOutputMatchesKind()` already ships.
 *
 * That pairing is what lets a read-only resource read honestly. `tenants` today says it twice in two
 * vocabularies — `sourceKind: 'service'` AND `creatable: false` — where what is true is that its backing
 * *could* write and the resource is declared closed.
 *
 * A read-only backing simply does not implement this, and then no affordance may open.
 *
 * ## Subject resolution belongs here
 *
 * A write needs the mutable record, not a projection of it, which is why this carries its own
 * {@see resolveForWrite()} rather than reusing {@see ResolvesRecord::resolve()} (whose job is a
 * projected detail READ). The two are genuinely different reads of the same id.
 */
interface WritesRecords extends ResourceBacking
{
    /**
     * Resolve the mutable subject of an update/delete by id, or null when it does not exist.
     *
     * @param  array<string, mixed>  $filters  the request's opaque bag — the SAME argument shape every
     *                                         other capability takes
     */
    public function resolveForWrite(string $id, array $filters): ?Model;

    /**
     * A fresh, unpersisted record for a create.
     */
    public function newRecord(): Model;
}
