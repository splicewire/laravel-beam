<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (06 amend, build-qa and review-r1 2026-11-16): a nested Data class reached through a `Data|Optional` union, the
 * type the generator emits for a map-entry key some entries lack. The coverage, draft and live-key walkers must walk in.
 */
#[ConfigRoot(root: 'qa.optional-nested', title: 'Optional nested')]
class OptionalNestedSection extends ConfigSection
{
    public function __construct(
        public OptionalNestedInner|Optional $inner,
    ) {}
}
