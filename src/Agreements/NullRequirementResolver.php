<?php

namespace Splicewire\Beam\Agreements;

/** The core default: hosts without the agreements seam add no new refusals. */
class NullRequirementResolver implements RequirementResolver
{
    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        return [];
    }
}
