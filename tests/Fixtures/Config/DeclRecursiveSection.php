<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (config-sections 21, Codex review lane A): a record type that RECURSES through a list of itself
 * ({@see DeclRecursiveNode}), so its secret is reached inside a list only on the type's second visit.
 */
#[ConfigRoot(root: 'qa.recursive', title: 'Recursive')]
class DeclRecursiveSection extends ConfigSection
{
    public function __construct(
        public DeclRecursiveNode $tree,
    ) {}
}
