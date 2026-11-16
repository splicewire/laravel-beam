<?php

namespace Splicewire\Beam\Config\Attributes;

use Attribute;

/**
 * Declares that a key's value is deliberately not described below this point (ADR-0245; config-sections ticket 06): its
 * children are covered as one value, and {@see \Splicewire\Beam\Doctor\ConfigSectionCoverageAudit} does not report them.
 *
 * The reason is required, so an opaque key is a decision someone wrote down rather than an omission: a value nothing
 * can describe statically (a list of arbitrary rows, an array a host builds), or one deferred on purpose.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ConfigOpaque
{
    public function __construct(
        public string $reason,
    ) {}
}
