<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\WriteRequirement;

/** Default no-op for hosts that do not install an agreements recording adapter. */
final class NullAssentRecorder implements AssentRecorder
{
    public function record(Model $subject, WriteRequirement $requirement): void
    {
        // Deliberately empty: requirement admission alone never implies assent recording.
    }
}
