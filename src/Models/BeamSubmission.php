<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;

/**
 * The generic submission reference — a beam-native companion to a {@see SchemaRecord}, composition
 * NOT inheritance (spec §2, the narrow-core precedent). One submission = one SchemaRecord (the
 * payload) + one BeamSubmission (the generic submission facets + reference). It carries ONLY facets
 * that ANY beam app with user input produces, never a form-runtime concept:
 *
 *   schema_record_id  — FK → the narrow record it references
 *   submitted_by      — nullable (public forms are anonymous)
 *   submitted_at      — when it arrived
 *   source / channel  — where it came from (web form, api, relay, …)
 *   context           — IP / user-agent / honeypot metadata (json)
 *
 * There is deliberately NO `form_key` here — the referenced record already bears which schema it is
 * (its `schema_ref`, §7). Domain-specific populator facts (a form key, generation provenance, …)
 * ride the OWNING domain package's own model, not this generic reference.
 *
 * The submission-created signal is the ordinary Eloquent `BeamSubmission::created` event — the
 * notify seam (a separate ticket) binds to it.
 */
class BeamSubmission extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('beam.tables.submissions', 'beam_submissions');
    }

    /**
     * The narrow schema record this submission references. Resolved through the swappable model
     * config so a host that composes {@see PersistsSchemaRecord} on its
     * own record can point the reference at it.
     *
     * @return BelongsTo<Model, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(
            config('beam.models.schema_record', SchemaRecord::class),
            'schema_record_id',
        );
    }
}
