<?php

namespace Splicewire\Beam\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Splicewire\Beam\Activity\ActivityRecorder;

/**
 * **Reversible attribute revisions** — the generic reversibility port, backed by
 * `spatie/laravel-activitylog`. Generalized up from composition's app-local `DataActivityRecorder`
 * (ADR-0049) so it becomes beam-core: any beam BeamParticle (or any Eloquent-backed record) gets
 * change-history + undo/redo.
 *
 * The record/history/projection MECHANICS now live in the {@see ActivityRecorder} base ("activity
 * onto the log substrate") — this class is the specialization that means it: an entry here is a
 * restorable attribute pre-image, and {@see self::revert()} restores it (append-only — the
 * reversal is itself recorded). A recorder for gestures that merely HAPPENED (a rank toggle, a
 * rate) extends {@see ActivityRecorder} directly instead; a rank is activity, not a revision.
 *
 * Usable directly on any record; specialized by extension for a specific payload shape (e.g.
 * composition's `CellRevisionRecorder`, which pins the payload to a cell's `slots` and re-derives
 * the projection on revert). Storage rides the default connection, which stancl/tenancy swaps per
 * tenant, so revisions stay tenant-local.
 *
 * `correlation` groups the entries of one intent (a batch), so a whole run can be reverted by
 * querying it. Every reversal is itself recorded (append-only).
 */
class RevisionRecorder extends ActivityRecorder
{
    /**
     * The `log_name` category — the seam that keeps future selective sync open. A specialization
     * overrides it (e.g. `cell-revision`).
     */
    protected function logName(): string
    {
        return 'beam-revision';
    }

    /**
     * Restore a prior state. Append-only: the reversal itself is recorded as a new entry.
     */
    public function revert(RevisionEntry $entry): Model
    {
        $subject = $this->resolveSubject($entry);

        $before = $subject->only(array_keys($entry->old));

        $subject->forceFill($entry->old)->save();

        // Append-only: the reversal is itself recorded, grouped with the intent it undoes.
        $this->record($subject, $before, $entry->old, 'revert', $entry->correlation);

        return $subject;
    }

    protected function resolveSubject(RevisionEntry $entry): Model
    {
        $class = Relation::getMorphedModel($entry->subjectType) ?? $entry->subjectType;

        /** @var Model $model */
        $model = new $class;

        return $model->newQuery()->findOrFail($entry->subjectId);
    }
}
