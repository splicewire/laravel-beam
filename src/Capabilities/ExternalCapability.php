<?php

namespace Splicewire\Beam\Capabilities;

/**
 * One declaration per gated feature (app ADR-0023, amended by app ADR-0205).
 *
 * Names both axes in a single place: the Meter it spends on (cost, app ADR-0024)
 * and the Entitlement it requires (access). The Entitlement Gate, the Cost Event
 * ledger, and Tenant Sync pre-flight all read this one contract — so adding such
 * a feature *means* implementing it; the precedent is structural, not a doc to
 * remember. Web Search is the first implementer.
 *
 * The two axes are genuinely separate (that is ADR-0023's whole argument), so only
 * the ACCESS axis is mandatory: {@see self::meter()} is nullable, and null means
 * "gated but free". A platform-hosted capability can be deliberately unmetered
 * (ADR-0135's music21 conformance) without being excluded from the registry.
 */
interface ExternalCapability
{
    /**
     * Stable capability key (e.g. 'web_search').
     */
    public function key(): string;

    /**
     * The Meter this capability spends on (e.g. 'google.cse.query'), or null when it
     * spends nothing — a gated-but-free capability (app ADR-0205).
     */
    public function meter(): ?string;

    /**
     * The Entitlement key required to use it (e.g. 'web_search').
     */
    public function requiredEntitlement(): string;
}
