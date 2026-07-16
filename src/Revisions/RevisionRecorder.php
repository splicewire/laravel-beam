<?php

namespace Schemastud\Beam\Revisions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * The generic reversibility port, backed by `spatie/laravel-activitylog`. Generalized up from
 * composition's app-local `DataActivityRecorder` (ADR-0049) so it becomes beam-core: any
 * beam SchemaRecord (or any Eloquent-backed record) gets change-history + undo/redo.
 *
 * Usable directly on any record; specialized by extension for a specific payload shape (e.g.
 * composition's `CellRevisionRecorder`, which pins the payload to a cell's `slots` and re-derives
 * the projection on revert). Storage rides the default connection, which stancl/tenancy swaps per
 * tenant, so revisions stay tenant-local.
 *
 * `correlation` groups the entries of one intent (a batch), so a whole run can be reverted by
 * querying it. Every reversal is itself recorded (append-only).
 */
class RevisionRecorder
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
        $logger = activity($this->logName())
            ->performedOn($subject)
            ->event($cause)
            ->withProperties(array_filter([
                'old' => $old,
                'new' => $new,
                'correlation' => $correlation,
            ], fn ($value) => $value !== null));

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $activity = $logger->log($cause);

        if ($activity === null) {
            throw new RuntimeException('Activity logging is disabled; a revision could not be recorded.');
        }

        return $this->toEntry($activity);
    }

    /**
     * Project an activity row into a revision entry. Seam: a specialization may return its own
     * {@see RevisionEntry} subclass (e.g. an app that adds typed projection fields) so its
     * consumers keep their own entry type across the whole recorder surface.
     *
     * @param  \Spatie\Activitylog\Contracts\Activity  $activity
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
        return $this->activityModel()::query()
            ->where('log_name', $this->logName())
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->get()
            ->map(fn (Activity $activity) => $this->toEntry($activity))
            ->all();
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

    /**
     * @return class-string<Activity>
     */
    protected function activityModel(): string
    {
        return config('activitylog.activity_model', Activity::class);
    }
}
