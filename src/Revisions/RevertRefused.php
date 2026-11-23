<?php

namespace Splicewire\Beam\Revisions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when a revert's write is refused — the subject's own `save()` returned false because a
 * halting `saving`/`updating` listener (a guard, a policy, a tenancy veto) vetoed it. When it is
 * raised, NOTHING has persisted: the pre-image was NOT applied to the subject AND no `revert`
 * activity was recorded.
 *
 * This matters because the revert is append-only — it records the reversal as a new entry — so a
 * silently-ignored `save()` boolean would log a `revert` for a write that never happened, teaching a
 * history that lies.
 *
 * The refusal is loud but its message carries NO subject identity: the subject's morph type and key
 * ride on {@see $subjectType}/{@see $subjectKey} so a host (such as a composition-writes layer that
 * maps the refusal to its own `*_write_refused` outcome) can read them and key its own user-facing
 * message, rather than forwarding this internal `[type:id]` string to the caller.
 */
class RevertRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $subjectType,
        public readonly int|string $subjectKey,
    ) {
        parent::__construct($message);
    }

    public static function for(Model $subject): self
    {
        return new self(
            'Refused to revert the record: the write was vetoed, so no revision was recorded and the record is unchanged.',
            $subject->getMorphClass(),
            $subject->getKey(),
        );
    }
}
