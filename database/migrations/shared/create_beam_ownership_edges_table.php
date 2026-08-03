<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Ownership\OwnershipEdgeType;

/**
 * The ownership / GC edge store (sourced-particles ticket 08, ADR-0161 Position 3 + MAP §Graphine).
 * Package-owned, ubiquitously provisioned (recohere T10): the same shared dir runs central + every
 * tenant. This SQUASHES the host-owned runnable copies (central + tenant twin) into one clean create.
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
