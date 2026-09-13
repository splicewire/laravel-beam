<?php

namespace Splicewire\Beam\Tests\Fixtures\Backing;

use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/** An arm that also DECLARES the facet it reads, so the composite has something to union. */
class DeclaringArm extends InMemoryArm implements DeclaresFilterVocabulary
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::search('label'));
    }
}
