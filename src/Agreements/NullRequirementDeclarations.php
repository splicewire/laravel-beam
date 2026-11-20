<?php

namespace Splicewire\Beam\Agreements;

/** Declares nothing: no behavior carries a Requirement, so no op gains an implied 428. */
class NullRequirementDeclarations implements RequirementDeclarations
{
    public function declares(string $behavior): bool
    {
        return false;
    }

    public function requirementsFor(string $behavior): array
    {
        return [];
    }

    public function all(): array
    {
        return [];
    }
}
