<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20 r3, review r2 HIGH): a list of records with a declared secret sits BELOW the pointer an
 * operator writes, in a nested Data class (`group`), in a map entry (`byName`) and in a union member (`maybe`).
 */
#[ConfigRoot(root: 'qa.relaygroup', title: 'Relay groups')]
class RelayGroupSection extends ConfigSection
{
    /**
     * @param  array<string, RelayGroup>  $byName
     * @param  array<string, RelayEntry>|Optional  $byId  a MAP keyed by numeric ids (stable names, not list indices)
     * @param  array<int, mixed>|Optional  $loose  an array with no item attribute (an undeclared shape)
     */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public RelayGroup $group,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live), MapValues(RelayGroup::class)]
        public array $byName,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public RelayGroup|Optional $maybe,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public RelayHolder $holder,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live), MapValues(RelayEntry::class)]
        public array|Optional $byId,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public array|Optional $loose,
    ) {}
}
