<?php

namespace Splicewire\Beam\Source;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Intake\PublicIntakeWriteGate;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * The {@see WriteGate} binding for source-originated writes (ADR-0161 Position 4, ticket 03) — the
 * write-path twin of the authority-aware silo gate (ticket 05). It permits a write ONLY when the actor
 * is a {@see SourceGrant} whose allow-list covers the record's schema stem, and DENIES everything else.
 *
 * Modelled on {@see PublicIntakeWriteGate}: permissive within an allow-list,
 * deny-by-default. The load-bearing move is refusing any actor that is NOT a `SourceGrant` — a real
 * user (→ the write would inherit that user's policy scope, the "shadow anything" escalation) and a
 * null/guest actor are both denied. A source's write authority is exactly its grant, nothing more.
 *
 * The "never a local tier" half of the rule lives on {@see SourceGrant::mayWriteTier} (the shadow
 * writer consults it before choosing an intensity — ticket 06); merge-on-materialize tag/silo minting
 * (ticket 07) routes its writes through this same gate, so a source can only mint vocabulary it is
 * granted.
 */
class SourceWriteGate implements WriteGate
{
    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
    {
        // Only a source grant may write through this gate. A real user or null is denied here — a source
        // write must never borrow a user's scope (escalation) nor fall through as an anonymous guest.
        if (! $actor instanceof SourceGrant) {
            return false;
        }

        $stem = $this->stemOf($subject);

        if ($stem === null) {
            return false;
        }

        return $actor->permits($stem);
    }

    /** The schema stem the subject writes under, or null when it carries no binding. */
    private function stemOf(Model|string $subject): ?string
    {
        if ($subject instanceof Model) {
            return method_exists($subject, 'recordType') ? $subject->recordType() : null;
        }

        return $subject === '' ? null : SchemaId::from($subject)->recordType();
    }
}
