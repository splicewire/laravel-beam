<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** A Data object that refuses undeclared input keys, for the laravel-beam sweep's coded-refusal test (localization 21). */
class StrictSweepInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[MapName('wireName')]
        public string $wireName,
    ) {}
}
