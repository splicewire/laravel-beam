<?php

namespace Splicewire\Beam\Revisions;

use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\LaravelData\Data;

/**
 * A typed projection of one activity-log row — the substrate-level revision record. Generalized
 * up from composition's app-local `RevisionEntry` (ADR-0049 §2) so *every* beam SchemaRecord —
 * generation-, edit-, or submission-populated — gets change-history + undo/redo, not just
 * composition cells.
 *
 * activitylog's loose `properties` JSON is an impl detail hidden here: `old`/`new`/`correlation`
 * are lifted into typed fields so history is schema-projectable, filterable, and form-renderable.
 */
class RevisionEntry extends Data
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
