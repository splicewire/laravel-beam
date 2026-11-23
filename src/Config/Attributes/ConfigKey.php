<?php

namespace Splicewire\Beam\Config\Attributes;

use Attribute;
use InvalidArgumentException;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Declares how one key of a Config Section behaves on the Override Ladder (ADR-0245). Placed on a property of a
 * {@see \Splicewire\Beam\Config\ConfigSection}, or of the entry class a map-shaped section names.
 *
 * Every slot defaults to the conservative answer, so an undeclared key and a bare `#[ConfigKey]` are the same
 * key: not overridable (`ceiling: Host`), deploy-only, not secret, replaced on merge. Adopting the convention
 * therefore changes no behaviour until a key is opted in.
 *
 * - `ceiling`: the highest tier that may override the key. The package declares it; an operator may only narrow
 *   it (a lock), never widen it.
 * - `closed`: runtime tiers BELOW the ceiling that may still not override the key (config-sections 17). A key with
 *   `ceiling: Tenant, closed: [ConfigTier::Operator]` is one each workspace sets for itself while the operator is
 *   kept out (a tenant's own API key): an operator draft is refused, an operator row already stored is ignored when
 *   the overlay applies, and the tenant hop starts from the host value. `null` declares nothing either way; `[]`
 *   declares the key open to every tier its ceiling admits, which the section audit accepts for a secret.
 *   Only `Operator` and `Tenant` can be closed (the tiers that write overrides). Closure is sticky: a key below a
 *   closed key is closed to the same tiers.
 * - `env`: informational. The env scan finds `env()` bindings itself; this names one the scan cannot see (a
 *   value a provider reads from the environment directly).
 * - Title and description are the data-schemas `#[Title]` / `#[Description]` attributes, so they reach the
 *   section's JSON Schema through the same generator as every other Data class.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ConfigKey
{
    /**
     * @param  list<ConfigTier>|null  $closed
     */
    public function __construct(
        public ConfigTier $ceiling = ConfigTier::Host,
        public ConfigEffect $effect = ConfigEffect::Deploy,
        public bool $secret = false,
        public ConfigMerge $merge = ConfigMerge::Replace,
        public ?string $env = null,
        public ?array $closed = null,
    ) {
        foreach ($closed ?? [] as $tier) {
            if (! in_array($tier, [ConfigTier::Operator, ConfigTier::Tenant], true)) {
                throw new InvalidArgumentException('#[ConfigKey(closed: ...)] names override tiers only: ConfigTier::Operator, ConfigTier::Tenant.');
            }
        }

        if ($closed !== null) {
            $unique = [];

            foreach ($closed as $tier) {
                $unique[$tier->value] = $tier;
            }

            $this->closed = array_values($unique);
        }
    }

    /** Whether the key is declared closed to `$tier`. */
    public function closedTo(ConfigTier $tier): bool
    {
        return in_array($tier, $this->closed ?? [], true);
    }

    /** Whether `$tier` may override the key: its ceiling admits the tier and the key is not closed to it. */
    public function opensTo(ConfigTier $tier): bool
    {
        return $this->ceiling->admits($tier) && ! $this->closedTo($tier);
    }
}
