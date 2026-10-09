<?php

namespace Splicewire\Beam\Data\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Documents a deliberate snake_case wire vocabulary owned by an external protocol or authoring
 * format. This is evidence for the wire-name audit, not a mapper and not an input-validation bypass.
 * The DTO must still declare the mapping that makes the documented vocabulary true.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class WireNameExemption
{
    public function __construct(
        public string $format,
        public string $citation,
        public string $reason,
    ) {
        foreach (['format' => $format, 'citation' => $citation, 'reason' => $reason] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Wire-name exemption {$field} must not be empty.");
            }
        }
    }
}
