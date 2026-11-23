<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 21): {@see RelayGroupSection}'s root with `group.relays` declared as a MAP of records, so a
 * test can store rows addressed at one item (`/group/relays/0`, a stable name here) that a later declaration of the same
 * key as a LIST turns into list-index rows: the rows stored before the ticket.
 */
#[ConfigRoot(root: 'qa.relaygroup', title: 'Relay groups (before)')]
class LegacyRelayGroupSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public LegacyRelayGroup $group,
    ) {}
}
