<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;
use Splicewire\Beam\BeamServiceProvider;

/**
 * Catches the gap this ticket set out to close: beam ships several `Surgeon/` audits guarded by
 * `interface_exists(SuggestsOperations::class)` (see {@see BeamServiceProvider}'s
 * `registerSurgeonAudits()`) — a host that never requires `rushing/laravel-surgeon` gets them
 * silently inert, with nothing telling it so. This audit reads the host's OWN composer.json — not
 * runtime interface presence, since the whole point is to fire even in a process where surgeon
 * happens to be autoloadable (e.g. beam's own testbench, where it's a dev dependency) but the
 * host's manifest never actually required it. Advisory: requiring surgeon is optional, so this
 * never fails the doctor exit code.
 */
class SurgeonWiringAudit
{
    private const SURGEON_PACKAGE = 'rushing/laravel-surgeon';

    /**
     * @param  array<string, mixed>  $composerJson
     */
    public function run(array $composerJson): Finding
    {
        $check = 'surgeon audits wired';

        $requires = array_keys(($composerJson['require'] ?? []) + ($composerJson['require-dev'] ?? []));

        if (in_array(self::SURGEON_PACKAGE, $requires, true)) {
            return Finding::pass(
                $check,
                self::SURGEON_PACKAGE.' is required — beam\'s Surgeon/ audits are discoverable via `surgeon:audit`.',
            );
        }

        return Finding::warn(
            $check,
            'beam ships Surgeon/ audits (HouseStyleAudit, SdkEndpointDriftAudit, etc.) that stay silently inert without '.
            self::SURGEON_PACKAGE.' — require it to activate `surgeon:audit` discovery.',
        );
    }
}
