<?php

namespace Splicewire\Beam\Agreements;

/** The core default: hosts without the agreements seam attach no Requirements, so no op gains an implied 428. */
class NullRequirementDeclarations implements RequirementDeclarations
{
    public function declares(string $behavior): bool
    {
        return false;
    }
}
