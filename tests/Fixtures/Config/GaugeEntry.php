<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class GaugeEntry extends Data
{
    public function __construct(
        #[Title('Label'), Description('Shown in pickers.')]
        public string $label,
        #[Title('Unit')]
        public string $unit,
        #[Title('Unit cost (USD)')]
        public float|Optional $unit_cost_usd,
    ) {}
}
