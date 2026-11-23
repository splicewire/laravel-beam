<?php

namespace Splicewire\Beam\Agreements\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/**
 * A caller's reference to the exact Agreement version submitted with a behavior; admission never records assent.
 *
 * Titled (agreements 16, from 14's finding): every host input that carries a member reaches this class, so without a
 * title it rendered its identifier and each host's IdentifierLabelTest flagged it.
 */
#[Title('Agreement member')]
class AgreementMemberData extends Data
{
    public function __construct(public string $key, public int $version, public string $digest) {}
}
