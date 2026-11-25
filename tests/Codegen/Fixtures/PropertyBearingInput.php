<?php

namespace Splicewire\Beam\Tests\Codegen\Fixtures;

use Splicewire\Beam\Data\BeamData;

/** owner-review-20261112 53: the property-bearing parent of {@see InheritedPropertiesInput}. */
class PropertyBearingInput extends BeamData
{
    public function __construct(
        public string $term,
    ) {}
}
