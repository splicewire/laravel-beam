<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Facades\Beam as BeamFacade;

/**
 * Nags for as long as the deprecated static bridge {@see Beam} exists (beam-facade ticket 05).
 *
 * The bridge is the one part of the facade design that is SUPPOSED to die: ticket 08 deletes it once the
 * estate sweep has repointed every call site onto {@see BeamFacade}. A done-condition
 * on a ticket is a weaker guarantee than a check that runs, so this warns on every
 * `splicewire:beam:doctor` until the class is gone — at which point it reports a Pass and is itself
 * deleted alongside the bridge.
 *
 * Advisory, never a gate: the bridge existing is the intended mid-sweep state, and failing the build on
 * it would block exactly the work that removes it. `trigger_error(E_USER_DEPRECATED)` was rejected for
 * the same job — it would fire hundreds of times per test run across 16 repos and be suppressed within
 * a day.
 */
class StaticBridgeAudit implements DoctorAudit
{
    /** @return list<Finding> */
    public function run(): array
    {
        $check = 'beam-facade.static-bridge-retired';

        if (! class_exists(Beam::class)) {
            return [Finding::pass($check, 'The deprecated static bridge is gone — call sites resolve through the facade.')];
        }

        return [Finding::warn(
            $check,
            'Splicewire\Beam\Beam is still present — the deprecated static bridge kept for the mid-sweep estate. '
            .'Import Splicewire\Beam\Facades\Beam at every call site, then delete the bridge and this audit '
            .'(beam-facade ticket 08).',
        )];
    }
}
