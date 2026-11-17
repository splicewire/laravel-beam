<?php

namespace Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard\Elsewhere\ProbeScope;

/** Labelled itself; reaches the unlabelled {@see ProbeScope}. */
#[Title('Probe')]
class ProbeLabelledData extends Data
{
    public function __construct(
        public ProbeScope $scope,
    ) {}
}
