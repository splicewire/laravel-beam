<?php

namespace Splicewire\Beam\Particle\Backing;

use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteSubjectNotEloquent;

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
 *
 * ## Persistence-agnostic, as of particle-write-surface ticket 07
 *
 * Both methods used to return `Illuminate\Database\Eloquent\Model`, which made this capability
 * **unsatisfiable** for a backing over an external system — and so the estate's three non-Eloquent
 * backings all implement exactly `ResolvesRecord, StreamsRecords`, the only combination the signatures
 * permitted. They now traffic in {@see WritableRecord}, mirroring what {@see ResolvedRecord} already did
 * for the read side, so *"data can be written to any number of places"* is expressible here.
 *
 * ⚠️ **Expressible at this boundary is not yet persistable below it.** The pipeline
 * ({@see WriteContext}, `PersistStage`, the write gate,
 * {@see BeamParticlePersisted}) still requires an Eloquent model, asserted in
 * ONE named place — {@see WritableRecord::model()}, which refuses with
 * {@see WriteSubjectNotEloquent}. Ticket 07 scopes that migration out as a map
 * with the measured reasons; do not read these signatures as a claim that it is done.
 */
interface WritesRecords extends ResourceBacking
{
    /**
     * Resolve the mutable subject of an update/delete by id, or null when it does not exist.
     *
     * @param  array<string, mixed>  $filters  the request's opaque bag — the SAME argument shape every
     *                                         other capability takes
     *
     * ⚠️ A null return is "no such record", NOT a {@see WritableRecord} wrapping null.
     */
    public function resolveForWrite(string $id, array $filters): ?WritableRecord;

    /**
     * A fresh, unpersisted record for a create.
     */
    public function newRecord(): WritableRecord;
}
