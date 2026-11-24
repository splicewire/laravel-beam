<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;

/** Default no-op for hosts that do not install an agreements recording adapter. */
final class NullBehaviorAssentRecorder implements BehaviorAssentRecorder
{
    /** @param list<AgreementMemberData> $members */
    public function record(string $behavior, array $members, Model $party, Model $subject): array
    {
        return [];
    }
}
