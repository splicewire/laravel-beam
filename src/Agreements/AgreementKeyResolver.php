<?php

namespace Splicewire\Beam\Agreements;

/** Resolve a Requirement's concrete Agreement key from the behavior's subject. */
interface AgreementKeyResolver
{
    public function resolve(mixed $subject): string;
}
