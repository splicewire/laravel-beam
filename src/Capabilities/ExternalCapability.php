<?php

namespace Splicewire\Beam\Capabilities;

/**
 * One declaration per third-party-backed feature (app ADR-0023).
 *
 * Names both axes in a single place: the Meter it spends on (cost, app ADR-0024)
 * and the Entitlement it requires (access). The Entitlement Gate, the Cost Event
 * ledger, and Tenant Sync pre-flight all read this one contract — so adding such
 * a feature *means* implementing it; the precedent is structural, not a doc to
 * remember. Web Search is the first implementer.
 */
interface ExternalCapability
{
    /**
     * Stable capability key (e.g. 'web_search').
     */
    public function key(): string;

    /**
     * The Meter this capability spends on (e.g. 'google.cse.query').
     */
    public function meter(): string;

    /**
     * The Entitlement key required to use it (e.g. 'web_search').
     */
    public function requiredEntitlement(): string;
}
