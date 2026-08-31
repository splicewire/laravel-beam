<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteSubjectNotEloquent;

/**
 * ONE record a backing offers for WRITING — the mutable subject plus the (nullable) schema `$ref` it is
 * written under. The write-side sibling of {@see ResolvedRecord} (particle-write-surface ticket 07).
 *
 * ## The ruling
 *
 * > A backing exists so data can be sourced from and written to any number of places — not just Eloquent
 * > models.
 *
 * The read side already honoured it. {@see WritesRecords} did not: it was `resolveForWrite(): ?Model` and
 * `newRecord(): Model`, so a backing over an external system could not implement the write capability at
 * all. Measured 2026-08-31, all three non-Eloquent backings in the estate — tower's `MembershipSource` and
 * `ReviewQueueUnionSource`, beam-accounts' `MembershipSource` — implement exactly
 * `ResolvesRecord, StreamsRecords`. **That is not a coincidence; it is the only combination the signatures
 * permitted.**
 *
 * ## Why an envelope and not a widened `object` return
 *
 * The same reason {@see ResolvedRecord} is an envelope: `schemaRef` is a **per-record** axis. A backing
 * spanning two record types writes a different ref per row, *"which a resource-level binding cannot
 * express"* — `ResolvedRecord`'s own words, and the write side inherits the problem exactly.
 *
 * ⚠️ **`schemaRef` is carried and not yet consumed.** {@see WriteContext::binding()}
 * duck-types `schemaBinding()` off the model, which is the very per-resource read that cannot answer for a
 * multi-type backing — and which a non-model subject cannot answer at all. Wiring the two together is seam
 * #2 of ticket 07's follow-on map; the slot is declared here so the shape need not change when it is.
 *
 * ## Why NOT reuse `ResolvedRecord`
 *
 * `ResolvedRecord::$record` is a spatie `Data` — a *projection*, for a detail READ. {@see WritesRecords}'s
 * own docblock calls the two *"genuinely different reads of the same id"*: a write subject is mutable and
 * persistable, a projection is neither. Reusing it would hand the persist stage a read projection to
 * `save()`.
 *
 * ## `$subject` is `object`, and {@see WritableRecord::model()} is the one place that is not
 *
 * The write PIPELINE still requires an Eloquent model, and ticket 07 scopes that migration out with
 * reasons. {@see WritableRecord::model()} is the single named seam where that requirement is asserted — one place for the
 * follow-on map to remove, rather than a constraint scattered as an `instanceof` through every consumer.
 * It refuses with {@see WriteSubjectNotEloquent}, never a `TypeError`, so the gap is legible.
 *
 * Deliberately a plain object and NOT a spatie `Data` (which `ResolvedRecord` is): a `Data` is an immutable
 * projection by construction, and the whole job of this envelope is to carry something the resource's
 * `prepare` hook and the persist stage will MUTATE.
 */
class WritableRecord
{
    public function __construct(
        public object $subject,
        public ?string $schemaRef = null,
    ) {}

    /**
     * The subject as an Eloquent model — the one thing the write pipeline still requires of it.
     *
     * @throws WriteSubjectNotEloquent the subject is not a model, so this pipeline cannot persist it
     */
    public function model(): Model
    {
        if (! $this->subject instanceof Model) {
            throw WriteSubjectNotEloquent::for($this->subject);
        }

        return $this->subject;
    }
}
