<?php

namespace Splicewire\Beam\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Contracts\Activity;
use Splicewire\Beam\Activity\ActivityRecorder;
use Splicewire\Beam\Models\BeamParticle;

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
     * {@inheritDoc}
     *
     * Covariant narrowing: an entry recorded here is a restorable pre-image, so the whole revision
     * surface keeps returning {@see RevisionEntry} — existing consumers see no change.
     *
     * @param  array<string, mixed>  $old  the pre-image (attribute subset) being replaced
     * @param  array<string, mixed>  $new  the post-image
     */
    public function record(
        Model $subject,
        array $old,
        array $new,
        string $cause,
        ?string $correlation = null,
        ?Model $actor = null,
    ): RevisionEntry {
        /** @var RevisionEntry $entry — {@see self::toEntry()} pins the projection */
        $entry = parent::record($subject, $old, $new, $cause, $correlation, $actor);

        return $entry;
    }

    /**
     * Project into the revision-named entry (via the {@see ActivityRecorder::toEntry()} seam), so
     * every path through this recorder — record, history, revert — carries the restorable type.
     *
     * @param  Activity  $activity
     */
    protected function toEntry($activity): RevisionEntry
    {
        return RevisionEntry::fromActivity($activity);
    }

    /**
     * The subject's revisions, newest first.
     *
     * @return list<RevisionEntry>
     */
    public function history(Model $subject): array
    {
        /** @var list<RevisionEntry> */
        return parent::history($subject);
    }

    /**
     * Restore a prior state. Append-only: the reversal itself is recorded as a new entry.
     *
     * @throws RevertRefused when the restoring write is vetoed (a halting `saving`/`updating` listener
     *                       makes `save()` return false): the reversal is NOT recorded.
     */
    public function revert(RevisionEntry $entry): Model
    {
        $subject = $this->resolveSubject($entry);

        $before = $subject->only(array_keys($entry->old));

        $restored = $entry->old;
        if ($subject instanceof BeamParticle && (isset($subject->meta['owner']) || isset($subject->meta['workflow']))) {
            // Dispatch through the OVERRIDABLE restoreVersionSnapshot() — not a private worker — so a
            // BeamParticle subclass that overrides restore is honoured on revert too. Clear the outcome
            // slot first, then read it after: a null reading means the base impl was bypassed by an
            // override that never called parent (unknown, treated as stored below).
            $subject->clearRestoreOutcome();
            $subject->restoreVersionSnapshot(array_replace($subject->toVersionSnapshot(), $entry->old));
            $restored = $subject->only(array_keys($entry->old));
            $saved = $subject->restoreOutcome();
        } else {
            $saved = $subject->forceFill($entry->old)->save();
        }

        // The write can be refused: a halting `saving`/`updating` listener (a guard, a policy, a
        // tenancy veto) makes `save()` return false without persisting. Because the revert is
        // append-only, recording the reversal when the restored state never landed would log a
        // `revert` for a write that did not store — a history that lies. The `save()` boolean is the
        // only signal that survives a NO-OP revert: when the pre-image already equals the live values,
        // the restore mutates nothing, so `isDirty()` stays false whether the write stored or a
        // listener vetoed it — the old `isDirty()` predicate silently recorded a `revert` for the
        // refused one. The owner branch carries the outcome on BeamParticle's restore-outcome slot
        // (without widening the `: void` Versionable contract). ONLY a strict `false` is a refusal: a
        // non-report (null — an override that bypassed the base impl, or a save()-override returning
        // null) is treated as stored, so this never newly throws on a write that may have landed.
        if ($saved === false) {
            throw RevertRefused::for($subject);
        }

        // Append-only: the reversal is itself recorded, grouped with the intent it undoes.
        $this->record($subject, $before, $restored, 'revert', $entry->correlation);

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
