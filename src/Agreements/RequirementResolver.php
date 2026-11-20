<?php

namespace Splicewire\Beam\Agreements;

use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;

/** The transport-neutral seam that reports unmet requirements for one attempted behavior. */
interface RequirementResolver
{
    /**
     * @param  array<string, AgreementMemberData>  $submittedMembers  keyed by the input member name
     * @return list<OutstandingRequirementData>
     */
    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array;
}
