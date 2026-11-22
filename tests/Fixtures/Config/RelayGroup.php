<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/** Fixture (config-sections 20 r3): a LIST of records ({@see RelayEntry}, `password` secret) inside a nested Data class. */
class RelayGroup extends Data
{
    /** @param  array<int, RelayEntry>  $relays */
    public function __construct(
        #[DataCollectionOf(RelayEntry::class)]
        public array $relays,
    ) {}
}
