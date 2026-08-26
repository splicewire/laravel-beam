<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\Hook;

/**
 * Deletes a hook when its subject is deleted (api-surface-coherence ticket 38, decided by 12 §1).
 *
 * ## Deleted, not nulled
 *
 * Nulling `subject_id` would silently PROMOTE a narrow subscription — "tell me when THIS composition
 * renders" — into a firehose over every composition in the tenant, at the exact moment nobody is
 * watching. That is a privacy failure dressed as a cleanup, so the row goes.
 *
 * ## Why a wildcard listener and not a database cascade
 *
 * `subject_type`/`subject_id` is a polymorphic reference and a foreign key cannot express one. The
 * alternative — an observer per subscribable model — would need every host to remember to register
 * one for every model anyone might ever narrow a hook to, and the failure mode of forgetting is the
 * silent promotion above.
 *
 * ## The cost, and the memo that pays it
 *
 * This fires on `eloquent.deleted: *` — every model deletion in the host. A bare indexed lookup per
 * deletion would be one query on every delete in the application forever, for a feature most hosts
 * use zero times. So the DISTINCT `subject_type` set is memoized per process and only a class that
 * appears in it reaches the database; {@see forget()} drops the memo whenever a hook is written, and
 * a queue worker's long-lived process therefore stays correct rather than merely fast.
 */
class HookSubjectPruner
{
    /** @var list<string>|null */
    private static ?array $subjectTypes = null;

    /**
     * Wildcard listener signature: `handle($eventName, $payload)` where `$payload[0]` is the model.
     *
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || $model instanceof Hook) {
            return;
        }

        if (! in_array($model->getMorphClass(), $this->subjectTypes(), true)) {
            return;
        }

        Hook::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->delete();
    }

    /**
     * The subject classes any hook currently narrows to. Empty — the overwhelmingly common case —
     * short-circuits every deletion in the host to an in-memory `in_array` against an empty list.
     *
     * A missing table answers empty rather than throwing: this listener is armed from a service
     * provider and fires during `migrate:fresh`, install, and any test that deletes a model before
     * the shared migrations have run. Throwing there would make beam impossible to install, which is
     * the same host-dependent-fatality shape ticket 91 corrected in the event catalog.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        if (self::$subjectTypes !== null) {
            return self::$subjectTypes;
        }

        try {
            if (! Schema::hasTable(Beam::table('hooks'))) {
                return self::$subjectTypes = [];
            }

            return self::$subjectTypes = array_values(array_filter(
                Hook::query()->distinct()->pluck('subject_type')->all(),
            ));
        } catch (\Throwable) {
            return self::$subjectTypes = [];
        }
    }

    /** Drop the memo — called whenever a hook row is written, so a long-lived worker stays correct. */
    public static function forget(): void
    {
        self::$subjectTypes = null;
    }
}
