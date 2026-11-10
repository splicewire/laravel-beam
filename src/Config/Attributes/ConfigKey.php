<?php

namespace Splicewire\Beam\Config\Attributes;

use Attribute;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Declares how one key of a Config Section behaves on the Override Ladder (ADR-0242). Placed on a property of a
 * {@see \Splicewire\Beam\Config\ConfigSection}, or of the entry class a map-shaped section names.
 *
 * Every slot defaults to the conservative answer, so an undeclared key and a bare `#[ConfigKey]` are the same
 * key: not overridable (`ceiling: Host`), deploy-only, not secret, replaced on merge. Adopting the convention
 * therefore changes no behaviour until a key is opted in.
 *
 * - `ceiling`: the highest tier that may override the key. The package declares it; an operator may only narrow
 *   it (a lock), never widen it.
 * - `env`: informational. The env scan finds `env()` bindings itself; this names one the scan cannot see (a
 *   value a provider reads from the environment directly).
 * - Title and description are the data-schemas `#[Title]` / `#[Description]` attributes, so they reach the
 *   section's JSON Schema through the same generator as every other Data class.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ConfigKey
{
    public function __construct(
        public ConfigTier $ceiling = ConfigTier::Host,
        public ConfigEffect $effect = ConfigEffect::Deploy,
        public bool $secret = false,
        public ConfigMerge $merge = ConfigMerge::Replace,
        public ?string $env = null,
    ) {}
}
