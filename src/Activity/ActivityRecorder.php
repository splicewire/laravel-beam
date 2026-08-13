<?php

namespace Splicewire\Beam\Activity;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Revisions\RevisionEntry;
use Splicewire\Beam\Revisions\RevisionRecorder;

/**
 * **Activity onto the log substrate** — the generic record-onto-ActivityLog mechanics, backed by
 * `spatie/laravel-activitylog`: a `log_name` category seam, `record()` with old/new payload images,
 * correlation grouping, an optional causer, subject history, and the typed entry projection.
 *
 * Extracted UP from {@see RevisionRecorder} (which now extends this class) because not every
 * recorded gesture is a revision: a rank toggle/rate is ACTIVITY — something that happened to a
 * subject — not a reversible attribute mutation. The split:
 *
 *   - `ActivityRecorder` (this class) — activity onto the log substrate: record / history / project.
 *   - {@see RevisionRecorder} — REVERSIBLE ATTRIBUTE REVISIONS: adds revert/undo semantics on top
 *     (the old-image is a restorable attribute subset, and every reversal is itself recorded).
 *
 * A recorder that only narrates (beam-rank's `RankRecorder`) extends this base; a recorder whose
 * entries must be restorable extends {@see RevisionRecorder}.
 *
 * Entries project into {@see RevisionEntry} — the established typed projection of one activity row
 * (old/new/correlation/cause lifted out of the loose `properties` JSON). The type predates this
 * split and stays in the `Revisions` namespace as the shared projection; the {@see self::toEntry()}
 * seam lets a specialization substitute its own subclass.
 *
 * NAMING NOTE (estate archaeology): tower ships a LEGACY app-tier
 * `Splicewire\Tower\Support\Activity\ActivityRecorder` — the reversibility-port *interface* that
 * predates the generalization of this machinery into beam-core (ADR-0049), alongside its
 * `DataActivityRecorder` default. Different namespace, different tier: THIS class is the
 * substrate-tier home of the mechanics; tower's pair are the pre-generalization ancestors and
 * candidates for a future fold-down onto beam-core (not folded here).
 *
 * Storage rides the default connection, which stancl/tenancy swaps per tenant, so entries stay
 * tenant-local. `correlation` groups the entries of one intent (a batch). Append-only.
 */
class ActivityRecorder
{
    /**
     * The `log_name` category — the seam that keeps future selective sync open. A specialization
     * overrides it (e.g. `beam-revision`, `beam-rank`, `cell-revision`).
     */
    protected function logName(): string
    {
        return 'beam-activity';
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
            throw new RuntimeException('Activity logging is disabled; the entry could not be recorded.');
        }

        return $this->toEntry($activity);
    }

    /**
     * Project an activity row into a typed entry. Seam: a specialization may return its own
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
     * The subject's entries under this recorder's log name, newest first.
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
     * @return class-string<Activity>
     */
    protected function activityModel(): string
    {
        return config('activitylog.activity_model', Activity::class);
    }
}
