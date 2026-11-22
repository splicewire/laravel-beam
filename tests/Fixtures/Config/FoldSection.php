<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20, review MEDIUM): a union of two Data classes that DISAGREE on `k` and declare no secret:
 * {@see FoldTenantData} says ceiling tenant, merge extend, effect live; {@see FoldHostData} says the bare default (host,
 * replace, deploy). The fold keeps the strictest: host, extend, deploy, in either member order ({@see FoldReversedSection}).
 */
#[ConfigRoot(root: 'qa.fold', title: 'Fold')]
class FoldSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public FoldTenantData|FoldHostData|Optional $x,
    ) {}
}
