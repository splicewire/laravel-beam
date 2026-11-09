<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

class WidgetLimits extends Data
{
    public function __construct(
        #[Title('Rows per page')]
        public int $per_page,
        #[Title('Maximum rows')]
        public int $max,
    ) {}
}
