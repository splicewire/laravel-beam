<?php

namespace Splicewire\Beam\Write;

use Splicewire\Beam\Agreements\Data\AgreementMemberData;

/**
 * What the write pipeline's {@see Stages\RequirementAdmissionStage} asks admission about (agreements 05): the
 * behavior (`{resource}:write`), the subject (the record for an update, null for a create) and the agreement members
 * the caller submitted. The acting party is the write's own actor.
 *
 * A resource transport hands one to {@see ParticleWriter::requiring()}; a write with none (every other caller) is
 * admitted without asking.
 */
final class WriteRequirement
{
    /** @param  array<string, AgreementMemberData>  $submittedMembers */
    public function __construct(
        public readonly string $behavior,
        public readonly mixed $subject = null,
        public readonly array $submittedMembers = [],
    ) {}
}
