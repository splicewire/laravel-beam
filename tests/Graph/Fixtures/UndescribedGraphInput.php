<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/** One described and one bare property: the negative control for GraphInputDescriptionTest. */
class UndescribedGraphInput extends Data
{
    public function __construct(
        #[Description('Described.')]
        public int $depth,
        public int $limit,
    ) {}
}
