<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;

/** Resolve a Requirement's concrete Agreement key from the behavior's model subject. */
interface AgreementKeyResolver
{
    public function resolve(Model $subject): string;
}
