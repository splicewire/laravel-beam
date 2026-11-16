<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (config-sections 14, review-r1): a section with its own titled property (`mode`) and a nested record whose
 * `label` is inherited from a parent class, to pin that PROPERTY titles resolve under the data rule (declaring class),
 * never under the section's `config` kind.
 */
#[ConfigRoot(root: 'qa.inherited', title: 'Inherited titles', description: 'Property titles keyed by their declaring class.')]
class InheritedTitleSection extends ConfigSection
{
    public function __construct(
        #[Title('Mode')]
        public string $mode,
        public InheritedLabelRecord $item,
    ) {}
}
