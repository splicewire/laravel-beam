<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\BeamDoctorCommand;

/**
 * The contract a consumer audit implements to be aggregated by {@see BeamDoctorManifest} — a single
 * argument-free `run()` returning {@see Finding}s. beam-core's own audits (dependency contract, MCP
 * isolation, …) predate the manifest and stay hardcoded in {@see BeamDoctorCommand}
 * with their bespoke `run(...)` signatures; this interface governs only the manifest-registered tail,
 * so a registered audit resolves from the container and runs with no per-check wiring in the command.
 */
interface DoctorAudit
{
    /**
     * @return list<Finding>
     */
    public function run(): array;
}
