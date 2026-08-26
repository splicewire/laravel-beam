<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;

/**
 * The `hooks` table (api-surface-coherence ticket 38, decided by ticket 12) — one row per tenant
 * subscription to a set of event names, delivered to one signed endpoint.
 *
 * Same publish-only stub convention every beam-core table ships under — see
 * `create_beam_particles_table.php.stub`'s docblock for the full rationale. It lands in
 * `shared/` because a hook is meaningful in BOTH the central pass (an operator-realm hook on
 * platform events) and every tenant pass (a tenant's own subscriptions) — ticket 12 §7's
 * "one file run in both passes", not a duplicated flat+tenant DDL pair.
 *
 * ## The columns that look redundant and are not
 *
 * - **`paused_at` vs `disabled_at`** (12 §4). `paused_at` is USER INTENT — the owner switched it
 *   off, or an emission-time entitlement check found a lapsed plan (ticket 13 §4, which pauses
 *   rather than failing precisely so a billing lapse never routes a user to `op/reset`).
 *   `disabled_at` is SYSTEM HEALTH — the endpoint failed `consecutive_failures` times running and
 *   auto-disabled. Delivery requires BOTH null. Collapsing them into one nullable column loses
 *   which party turned it off, and therefore which party may turn it back on.
 * - **no `resource` column.** The resource is the event name's first segment
 *   ({@see \Splicewire\Beam\Events\EventType::resourceKey()}). A stored resource key would be a
 *   fourth place for ticket 16's normalisation to rot, and it would be derivable-but-stale the
 *   moment a hook subscribed to two resources' events.
 * - **`entitlement_keys` and no permission name** (13 §6). The FEATURE-plane requirement is read
 *   off the `entitlement:*` middleware on the route the subscribe request arrived through and
 *   snapshotted here, because it is not re-derivable later — no request, no route. The
 *   ACTION-plane permission NAME is deliberately absent: it is re-derived from the particle stamp
 *   through `PermissionNamer` on every check.
 * - **`owner_type`/`owner_id` is AUDIT ONLY, not a scope** (12 §7). It records who created the
 *   hook. It does not filter reads and there is no index pretending otherwise.
 *
 * `subject_type`/`subject_id` is the nullable morph a hook narrows to one record (12 §1). The hook
 * is DELETED when its subject is — enforced in {@see \Splicewire\Beam\Models\Hook}'s observer
 * rather than by a database FK, because a polymorphic reference cannot carry one.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named(Beam::table('hooks'))
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->string('endpoint');
                // Minted at create and revealed ONCE (the `tokens` precedent). Stored in the clear
                // because it is an HMAC key the sender must present on every delivery — it is not a
                // credential the platform verifies, so there is nothing a hash could be compared to.
                $table->string('secret');
                // Additive, and caller-supplied: a bearer the receiver already knows. Orthogonal to
                // `secret` — a receiver may want both, and neither replaces the other.
                $table->string('token')->nullable();

                // The subscribed event NAMES, verbatim from the catalog. The resource is each name's
                // first segment; see the class docblock.
                $table->json('events');

                $table->nullableMorphs('subject');

                $table->timestamp('paused_at')->nullable();
                $table->timestamp('disabled_at')->nullable();

                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->uuid('last_failure_request_log_id')->nullable();

                $table->timestamp('verified_at')->nullable();

                // Snapshotted feature-plane requirement — see the class docblock.
                $table->json('entitlement_keys')->nullable();

                $table->nullableMorphs('owner');

                $table->timestamps();
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('hooks'));
    }
};
