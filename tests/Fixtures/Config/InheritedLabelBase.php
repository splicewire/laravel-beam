<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/**
 * Fixture (config-sections 14, review-r1): a parent Data class that DECLARES a titled property its child inherits, so the
 * property's title is keyed by this class (`data.<InheritedLabelBase>.properties.label.title`), not the child's.
 */
abstract class InheritedLabelBase extends Data
{
    #[Title('Label')]
    public string $label;
}
