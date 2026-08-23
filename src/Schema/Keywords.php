<?php

namespace Splicewire\Beam\Schema;

use Splicewire\Beam\Write\Stages\DedupeStage;

/**
 * The JSON-Schema extension keywords BEAM-CORE owns — the package's first (beam-facade ticket 66,
 * design in ticket 50 §3).
 *
 * Ownership doctrine (the JSON-LD `@context` model), carried over verbatim from
 * `Splicewire\Beam\Notifications\Keywords`, the precedent this file mirrors: the base leaf owns the
 * small cross-engine set (`@id`, `x-dereference`); every other package owns and guards its OWN
 * keywords locally. There is no central keyword list to curate — a keyword is legitimate because
 * some package declares it here, and drift is caught by each package asserting what it reads/emits
 * stays within `base ∪ own` (see `KeywordOwnershipTest`).
 *
 * `x-beam-dedupe` is homed HERE and not in `laravel-beam-notifications` because dedupe is a property
 * of CAPTURE, and capture is core's: {@see DedupeStage} runs in the shipped default write chain, so
 * homing the keyword in an optional package would tie a write-time concern to something a host may
 * not compose (ticket 50 §3).
 */
class Keywords
{
    /**
     * The declared family prefix. `x-beam-*` keywords belong to the schemastud beam capability
     * family; this package registers the prefix and owns `x-beam-dedupe`.
     */
    public const Prefix = 'x-beam';

    /**
     * How a capture behaves when its key matches an earlier one.
     *
     * `x-beam-dedupe: { by: ['email'], mode: 'admit' }` — `by` is the ordered list of payload fields
     * the match key is computed from, `mode` one of `reject | ignore | admit` (default `admit`).
     */
    public const Dedupe = 'x-beam-dedupe';

    /**
     * Every `x-` keyword this package owns / reads.
     *
     * @return list<string>
     */
    public static function owned(): array
    {
        return [self::Dedupe];
    }
}
