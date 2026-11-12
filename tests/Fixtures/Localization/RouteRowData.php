<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Data;

/**
 * localization 10 fixture: three list columns, one per case of ruling 01 §6.
 * - `target`: no label of its own, so it takes the property's title;
 * - `path`: its own label ("From"), which is a different word from its title, so it never borrows the title;
 * - `state`: a label equal to its title, which counts as declaring none.
 */
class RouteRowData extends Data
{
    public function __construct(
        #[Title('Destination')]
        #[Column(sort: 0)]
        public string $target = '',

        #[Title('Origin')]
        #[Column(label: 'From', sort: 1)]
        public string $path = '',

        #[Title('Status')]
        #[Column(label: 'Status', sort: 2)]
        public string $state = '',
    ) {}
}
