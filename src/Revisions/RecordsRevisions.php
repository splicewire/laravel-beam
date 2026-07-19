<?php

namespace Splicewire\Beam\Revisions;

use Illuminate\Database\Eloquent\Model;

/**
 * The beam-core revision trait: mix it into any SchemaRecord (or any Eloquent model) to give the
 * record activity-log-backed change-history + undo/redo. This is the generalization of
 * composition's app-local, cell-scoped revision mechanism up into beam (spec §8) — "frozen" only
 * ever meant *materialised*, never *immutable*, so beam records are mutable, referential, and
 * semi-versioned for undo/redo.
 *
 * The trait delegates to a {@see RevisionRecorder}; a host may point {@see self::revisionRecorder()}
 * at a specialization (e.g. one that re-derives a projection on revert). The default records the
 * full attribute pre-image/post-image the caller hands it.
 *
 * @mixin Model
 */
trait RecordsRevisions
{
    /**
     * The recorder that backs this model's revisions. Override to specialize (custom log name,
     * projection re-derivation on revert, …).
     */
    public function revisionRecorder(): RevisionRecorder
    {
        return app(RevisionRecorder::class);
    }

    /**
     * Record a mutation of this record.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function recordRevision(
        array $old,
        array $new,
        string $cause,
        ?string $correlation = null,
        ?Model $actor = null,
    ): RevisionEntry {
        return $this->revisionRecorder()->record($this, $old, $new, $cause, $correlation, $actor);
    }

    /**
     * This record's revisions, newest first.
     *
     * @return list<RevisionEntry>
     */
    public function revisions(): array
    {
        return $this->revisionRecorder()->history($this);
    }

    /**
     * Restore a prior state (append-only: the reversal is itself recorded).
     */
    public function revertTo(RevisionEntry $entry): Model
    {
        return $this->revisionRecorder()->revert($entry);
    }
}
