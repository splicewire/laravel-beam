<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Install\InstallStep;

/**
 * One consumer package's audit registration in the {@see BeamDoctorManifest} — the mirror of
 * {@see InstallStep} for the doctor side. Carries the audit class to resolve
 * and run, an ordering hint (core-first), and the gate/advisory flag: a `gate` audit's {@see DoctorStatus::Fail}
 * turns `splicewire:beam:doctor` red, an advisory one renders Pass/Warn/Fail but never fails the exit code.
 */
final class DoctorRegistration
{
    /**
     * @param  string  $package  the registering package's name (operator output only — beam never branches on it)
     * @param  class-string<DoctorAudit>  $audit  the audit to resolve from the container and run
     * @param  bool  $gate  whether a Fail from this audit blocks the exit code (default advisory)
     * @param  int  $order  lower runs first; beam-core's hardcoded audits render first, consumers default to 100
     */
    public function __construct(
        public readonly string $package,
        public readonly string $audit,
        public readonly bool $gate = false,
        public readonly int $order = 100,
    ) {}
}
