<?php

namespace Splicewire\Beam\Agreements\Data;

use Spatie\LaravelData\Data;

/** A caller's reference to the exact Agreement version submitted with a behavior; admission never records assent. */
class AgreementMemberData extends Data
{
    public function __construct(public string $key, public int $version, public string $digest) {}
}
