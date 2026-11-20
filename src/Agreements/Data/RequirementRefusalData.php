<?php

namespace Splicewire\Beam\Agreements\Data;

use Spatie\LaravelData\Data;

class RequirementRefusalData extends Data
{
    /** @param list<OutstandingRequirementData> $requirements */
    public function __construct(public array $requirements) {}
}
