<?php

namespace Splicewire\Beam\Source;

/**
 * The lifecycle tier a write targets (ADR-0161 Position 1 + §Decisions.6). A source-originated
 * write may only ever land in a SHADOW tier — never {@see self::Local} — which is the structural
 * half of the privilege-escalation guard ({@see SourceGrant::mayWriteTier}): a foreign payload can
 * become a provenance-marked shadow, but can never mint or overwrite a record this tenant owns.
 *
 *  - {@see self::Local}        — a first-class record this tenant authored; NEVER a source write.
 *  - {@see self::ShadowCached} — evictable, TTL'd, edit-denied; mints no durable shared vocabulary.
 *  - {@see self::ShadowPinned} — promoted + durable; the full write pipeline runs once on promote().
 *
 * Ticket 06 builds the persistence (the column + two-intensity writer) ON this enum; ticket 03
 * introduces it because the write gate's tier rule is expressed against it.
 */
enum SourceWriteTier: string
{
    case Local = 'local';
    case ShadowCached = 'shadow-cached';
    case ShadowPinned = 'shadow-pinned';

    /** True for the two shadow tiers a source write may target. */
    public function isShadow(): bool
    {
        return $this !== self::Local;
    }
}
