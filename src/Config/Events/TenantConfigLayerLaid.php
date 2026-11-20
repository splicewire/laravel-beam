<?php

namespace Splicewire\Beam\Config\Events;

/**
 * A tenant layer was just laid into the current config repository (config-sections ticket 09): at the hop
 * ({@see \Splicewire\Beam\Config\Overrides\ConfigTenantOverlay::apply()}, also on refresh() after a tenant publish) or
 * again by {@see \Splicewire\Beam\Config\Overrides\ConfigOverlay}'s operator re-sync mid-hop, which lifts the layer,
 * writes the operator tier and lays it back. A feature that rewrites a laid value (beam-tenancy's role alias) re-applies
 * itself here, or the re-lay hands the raw value back.
 *
 * Carries WHERE, never what: root => the JSON pointers laid. A value, secret or not, is read from `config()` by whoever
 * needs it. Dispatched only when something listens.
 */
final class TenantConfigLayerLaid
{
    /** @param  array<string, list<string>>  $pointers  root => pointers laid */
    public function __construct(public readonly array $pointers) {}

    public function touches(string $root): bool
    {
        return array_key_exists($root, $this->pointers);
    }
}
