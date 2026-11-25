<?php

namespace Splicewire\Beam\Tests\Codegen\Fixtures;

use Splicewire\Beam\Data\BeamData;

/** owner-review-20261112 53: a read input that declares no properties (the shape of beam-ux's capabilities input). */
final class PropertylessInput extends BeamData
{
    public function __construct() {}
}
