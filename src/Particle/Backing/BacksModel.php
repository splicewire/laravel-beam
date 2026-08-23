<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Model;

/**
 * Capability: every record this backing yields is an instance of ONE Eloquent model class.
 *
 * ## The tier rule (particle-contribution-seam ticket 11 §A7, ticket 01 §A5)
 *
 * > **A backing whose rows are all instances of one Eloquent model MUST implement `BacksModel`.**
 *
 * **Conditionally** required — *not* required of every backing. A backing whose rows span two record
 * types, or whose rows are pivot rows no single model identifies, has no legal answer for
 * {@see modelClass()}, which is exactly why this is a capability rather than a method on
 * {@see ResourceBacking} itself. Today's production population of backings that legitimately CANNOT
 * implement it is three — tower's `MembershipSource` and `ReviewQueueUnionSource`, beam-accounts'
 * `MembershipSource` — against ~30 that can. ⚠️ **That near-universality is a fact about today's
 * estate, not licence to promote `modelClass()` onto the port.**
 *
 * ⚠️ **The rule is about declaring what you BACK, not how you QUERY it.** A backing may satisfy this
 * and still run a hand-rolled query with batch-keyed side-loads — `TenantAdminSource` does, and that
 * query is legal residue, not a violation. Ticket 01 explicitly rejected extending the rule to force a
 * `BacksModel` backing through the model pipeline.
 *
 * ## What implementing it buys
 *
 * Entry in the {@see ModelResourceIndex} — the model→resource-keys reverse index. That index is
 * one-to-MANY (two resources legitimately share a model: a resource and its realm-varied twin), and it
 * is what `ParticleResourceModelResolver` and the Surgeon audits read instead of reflecting into the
 * registry's private state.
 */
interface BacksModel extends ResourceBacking
{
    /**
     * The single Eloquent model class every record this backing yields is an instance of.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string;
}
