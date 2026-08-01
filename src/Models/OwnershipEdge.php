<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Ownership\OwnershipEdgeType;

/**
 * One directed ownership / GC edge (sourced-particles ticket 08, ADR-0161 Position 3).
 *
 * TYPED endpoints — both `owner_id` and `target_id` are `beam_particles.id` uuids (see the migration
 * stub docblock for the morph-vs-typed decision). NOT audit-lineage: this row is live + cascade-bearing;
 * the `Lineage` model (tower-core) is the durability-first log and is untouched by this work.
 *
 * The table name routes through the single {@see Beam::table()} prefix seam, resolved in getTable()
 * (a `$table` property default cannot call config()), so a retrofit host's one prefix knob follows.
 */
class OwnershipEdge extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'target_id',
        'edge_type',
    ];

    protected $casts = [
        'edge_type' => OwnershipEdgeType::class,
    ];

    public function getTable(): string
    {
        return Beam::table('ownership_edges');
    }
}
