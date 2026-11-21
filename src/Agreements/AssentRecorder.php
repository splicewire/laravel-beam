<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\WriteRequirement;

/**
 * The optional recording side of an admitted write Requirement.
 *
 * This is a single container port, not a registry: callers send one saved subject and the
 * requirement for that write. REST and Frame construct a WriteRequirement for every create and
 * update, including writes with no declared Requirement, so the recorder is reached for every
 * transport write. An adapter must filter using RequirementDeclarations and/or submittedMembers
 * and treat all other writes as a no-op. The requirement subject is null on create and is the
 * existing model on update.
 *
 * Beam invokes it inside the subject model's persistence transaction, after identity creation and
 * before the after-write hook. It intentionally bypasses
 * {@see \Splicewire\Beam\Write\ScopedWriteGate}; the agreements adapter owns recording authority.
 * Write-kind operation handlers call this port inside their own transaction; see ADR-0247's
 * agreements 08 amendment.
 */
interface AssentRecorder
{
    public function record(Model $subject, WriteRequirement $requirement): void;
}
