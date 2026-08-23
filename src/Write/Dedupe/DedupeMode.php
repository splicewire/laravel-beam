<?php

namespace Splicewire\Beam\Write\Dedupe;

use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Write\Stages\DedupeStage;

/**
 * What a capture whose key matches an earlier one does (beam-facade ticket 50 §4).
 *
 * All three are LEDGER-side verdicts — they are about whether this row lands, never about the
 * deduplicated subject. `update` and `version` are deliberately NOT defined: they are about the
 * SUBJECT rather than the ledger, and `version` would additionally reverse a documented ruling
 * ({@see BeamSubmission} composes `ReconcilesPayloadOnRead` and pointedly
 * not `Versionable` — "a submission is immutable capture, not an editable milestone-versioned
 * doc"). Adding a fourth mode later is additive; that argument is the strongest objection to it.
 */
enum DedupeMode: string
{
    /**
     * The duplicate is REFUSED — {@see DuplicateRejected}, a 409, nothing persists.
     *
     * Ruled explicitly in ticket 50 §6: this IS an existence oracle by construction, because the
     * 409 itself is the disclosure. Legitimate only behind an authenticated or non-public door.
     */
    case Reject = 'reject';

    /**
     * The duplicate is DROPPED and the caller is handed the row that matched, indistinguishably
     * from a fresh {@see self::Admit} — 201, an id. Nothing persists, so no
     * `BeamParticlePersisted` and NO notification.
     */
    case Ignore = 'ignore';

    /**
     * The duplicate LANDS, stamped with its key and a `meta.dedupe.first_seen_id` linkage back to
     * the row it matched. The default, and the mode that preserves the capture ledger's
     * append-only contract (ticket 50 §1).
     */
    case Admit = 'admit';

    /**
     * The mode a declaration means when it carries the keyword and omits `mode`.
     *
     * `admit` and not `reject`: the ledger stays append-only unless a host asks otherwise, so
     * turning the keyword on never destroys a repeat's provenance by default (ticket 50 §1).
     * A stated default is not a door that closes — {@see DedupeStage}.
     */
    public static function default(): self
    {
        return self::Admit;
    }
}
