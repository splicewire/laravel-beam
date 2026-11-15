<?php

namespace Splicewire\Beam\Config\Overrides;

use Splicewire\Beam\Enums\ConfigOverrideOp;

/**
 * One pointer of a section's published override layer, as the overlay applies it and the reader reports it. `value`
 * is the effective value the tier sets (null for an explicit null; the DECRYPTED secret, which is only ever written
 * into the running configuration and never onto a wire or into a version).
 */
final class ConfigOverrideEntry
{
    public function __construct(
        public readonly string $pointer,
        public readonly ConfigOverrideOp $op,
        public readonly mixed $value,
        public readonly bool $secret,
        public readonly string $revision,
        public readonly ?int $version,
        public readonly ?string $setAt,
    ) {}
}
