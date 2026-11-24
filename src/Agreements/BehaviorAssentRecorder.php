<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;

/**
 * Records an Agreement member for a behavior before its first irreversible effect, where no record exists yet.
 *
 * The behavior that later creates a record stores the returned opaque references on that record.
 */
interface BehaviorAssentRecorder
{
    /**
     * @param  list<AgreementMemberData>  $members
     * @param  Model  $subject  the behavior's concrete subject; it exists before the behavior's irreversible effect
     * @return array<string, string> Agreement key => opaque ledger-entry reference
     */
    public function record(string $behavior, array $members, Model $party, Model $subject): array;
}
