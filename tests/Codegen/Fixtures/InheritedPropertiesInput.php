<?php

namespace Splicewire\Beam\Tests\Codegen\Fixtures;

/** owner-review-20261112 53: declares nothing itself but inherits `term`, so it is NOT a property-less input. */
final class InheritedPropertiesInput extends PropertyBearingInput {}
