<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Ownership\OwnershipEdgeType;

/**
 * The ownership / GC edge store (sourced-particles ticket 08, ADR-0161 Position 3 + MAP §Graphine).
 * Shipped as a publish-only migration (`runsMigrations` FALSE): the package ships this real-timestamped
 * copy and publishes it VERBATIM into the host via native `publishesMigrations` (natural timestamp
 * preserved, no re-stamp); the host runs it. beam-core never loadMigrationsFrom's it.
 *
 * TENANT TWIN of the ubiquitous ownership-edge store. Identical DDL to the flat
 * the flat `create_beam_ownership_edges_table.php`; this copy publishes into `database/migrations/tenant/`
 * (Stancl tenant pass) while the flat copy covers the central pass, so the edge store exists
 * identically wherever `beam_particles` does — both endpoints are `beam_particles.id` uuids, so the
 * graph is co-located with the particles it governs.
 *
 * NOT audit-lineage. Audit-lineage (`Lineage`, tower-core) is a durability-first derivation LOG that
 * SURVIVES producer deletion and cascades NOWHERE. This is a LIVE, refcounted, cascade-on-evict
 * ownership graph: "what dies / refreshes WITH me". Both endpoints are `beam_particles.id` uuids (a
 * single self-referential adjacency on ONE table) so the cascade stays a single-self-join recursive CTE.
 *
 * Two edge types ({@see OwnershipEdgeType}): `owns` (cascade-eligible + refcounted) and `references`
 * (never cascades). Table name routes through {@see Beam::table()} so a host prefix override follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Beam::table('ownership_edges');

        // Ubiquitous-table guard: a host that migrates BOTH the central and the tenant pass into ONE
        // schema (the shared-test-DB harness) would otherwise re-create this table. In production the
        // passes target separate schemas, so the guard is simply false. Mirrors the app's own ubiquitous
        // tables (e.g. tenant/create_users_table).
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) use ($table) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id');
            $t->uuid('target_id');
            $t->string('edge_type')->default(OwnershipEdgeType::Owns->value);
            $t->timestamps();

            $t->unique(['owner_id', 'target_id', 'edge_type'], $table.'_pair_unique');
            $t->index('owner_id', $table.'_owner_idx');
            $t->index(['target_id', 'edge_type'], $table.'_target_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('ownership_edges'));
    }
};
