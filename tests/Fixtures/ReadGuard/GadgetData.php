<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

use Spatie\LaravelData\Data;

/** The filter Data class behind the `gadgets` data-filters fixture — declares nothing, filters nothing. */
class GadgetData extends Data
{
    public function __construct(
        public string $id,
    ) {}
}
