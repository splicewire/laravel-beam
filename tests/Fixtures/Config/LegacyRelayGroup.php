<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 21): {@see RelayGroup} with `relays` a MAP of records keyed by name. */
class LegacyRelayGroup extends Data
{
    /**
     * @param  array<string, RelayEntry>  $relays
     * @param  array<int, string>|Optional  $tags
     */
    public function __construct(
        #[MapValues(RelayEntry::class)]
        public array $relays,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public array|Optional $tags,
    ) {}
}
