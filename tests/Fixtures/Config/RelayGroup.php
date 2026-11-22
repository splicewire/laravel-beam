<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20 r3/r4): a LIST of records ({@see RelayEntry}, `password` secret) inside a nested Data class,
 * and an optional list of SCALARS (`tags`).
 */
class RelayGroup extends Data
{
    /**
     * @param  array<int, RelayEntry>  $relays
     * @param  array<int, string>|Optional  $tags
     */
    public function __construct(
        #[DataCollectionOf(RelayEntry::class)]
        public array $relays,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public array|Optional $tags,
    ) {}
}
