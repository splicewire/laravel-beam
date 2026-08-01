<?php

namespace Splicewire\Beam\Write\Contracts;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\GateWriteGate;

/**
 * The beam-core authorization port for writes — **deny-by-default** (beam-write-pipeline ticket 03,
 * DESIGN §3a). No record type is ever writable unless something EXPLICITLY permits it: an
 * implementation returns `false` unless it can affirmatively authorize the write.
 *
 * This is the load-bearing safety seam (DESIGN §7 L5): a wrong default would silently make record
 * types publicly writable, so the default binding must deny and the public path must be an explicit
 * opt-in — never the absence of a rule.
 *
 * Two bindings express the two write worlds:
 *
 *  - {@see GateWriteGate} — beam-core's DEFAULT binding, delegates to the
 *    Laravel authorization gate, so today's `authorize('create'|'update', Model::class)` policies keep
 *    governing authenticated writes unchanged. Deny-by-default falls out for free: the Laravel gate
 *    returns false for any ability with no matching policy/definition.
 *  - a PERMISSIVE binding + a schema allow-list (ticket 04) — expresses "publicly submittable": only
 *    the specific schema stems a host marks public are writable by anonymous strangers.
 *
 * `$subject` is polymorphic to serve both worlds: a {@see Model} (the authenticated CRUD case — the
 * gate delegates to the model's policy, choosing `create`/`update` by whether it already exists) or a
 * bare schema-stem string (the public-intake case — the permissive binding consults its allow-list).
 */
interface WriteGate
{
    /**
     * May `$actor` write `$payload` to `$subject`? Returns false unless the write is explicitly
     * authorized (deny-by-default).
     *
     * @param  Model|string  $subject  a model instance/class (authenticated CRUD) or a schema stem (public intake)
     * @param  array<string, mixed>  $payload  the schema-shaped content about to be written
     * @param  mixed  $actor  the authenticated actor, or null for an anonymous write
     */
    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool;
}
