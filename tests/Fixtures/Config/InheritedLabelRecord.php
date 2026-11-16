<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Title;

/** Fixture ({@see InheritedTitleSection}): declares `size` itself and inherits `label` from {@see InheritedLabelBase}. */
class InheritedLabelRecord extends InheritedLabelBase
{
    public function __construct(
        #[Title('Size')]
        public int $size,
    ) {}
}
