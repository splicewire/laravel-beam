<?php

namespace Splicewire\Beam\Activity;

use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Revisions\RevisionEntry;
use Splicewire\Beam\Revisions\RevisionRecorder;

/**
 * A typed projection of one activity-log row — the substrate-level activity record. Extracted UP
 * from {@see RevisionEntry} (which now extends this class), mirroring
 * the {@see ActivityRecorder} / {@see RevisionRecorder} split: not every
 * recorded gesture is a revision, so the generic entry shape lives here and the revision-named type
 * is the reversibility specialization.
 *
 * activitylog's loose `properties` JSON is an impl detail hidden here: `old`/`new`/`correlation`
 * are lifted into typed fields so history is schema-projectable, filterable, and form-renderable.
 * Every field is generic activity data — the subject, an optional causer, the old/new payload
 * images, the cause, the correlation that groups one intent's entries, and the recorded-at
 * timestamp. What {@see RevisionEntry} adds is MEANING, not fields: its
 * `old` image is a restorable attribute pre-image, this one merely narrates.
 */
class ActivityEntry extends Data
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function __construct(
        public int $id,
        public string $logName,
        public string $cause,
        public ?string $correlation,
        public array $old,
        public array $new,
        public string $subjectType,
        public ?string $subjectId,
        public ?string $causerId,
        public ?string $recordedAt,
    ) {}

    public static function fromActivity(ActivityContract $activity): static
    {
        $props = collect($activity->properties ?? []);

        return new static(
            id: (int) $activity->id,
            logName: (string) $activity->log_name,
            cause: (string) ($activity->event ?? ''),
            correlation: $props->get('correlation'),
            old: (array) $props->get('old', []),
            new: (array) $props->get('new', []),
            subjectType: (string) $activity->subject_type,
            subjectId: $activity->subject_id !== null ? (string) $activity->subject_id : null,
            causerId: $activity->causer_id !== null ? (string) $activity->causer_id : null,
            recordedAt: $activity->created_at?->toIso8601String(),
        );
    }
}
