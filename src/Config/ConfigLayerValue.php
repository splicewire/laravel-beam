<?php

namespace Splicewire\Beam\Config;

use Splicewire\Beam\Enums\ConfigTier;

/**
 * One tier's value for one key. `shadowed` marks a package value the host's top-level copy hides; `removed` marks
 * a key a provider took out of `config()` (or, for an override tier, a key its ancestor entry's REPLACE leaves out).
 *
 * An override tier's entry also carries what it OWNS (config-sections 07, review-r1): `owned`, its value WITHOUT the
 * leaves it inherits (null reads `value`), and `inherit`, those leaves' relative pointers. {@see ConfigLadder::fold()}
 * resolves a descendant of the entry's pointer through them: in `owned` it is the entry's; in `inherit` it falls through
 * to the tier below; in neither it is absent, as the runtime's replace leaves it.
 */
class ConfigLayerValue
{
    /**
     * @param  list<string>  $inherit
     */
    public function __construct(
        public ConfigTier $tier,
        public mixed $value,
        public ?string $source,
        public bool $shadowed = false,
        public bool $removed = false,
        public mixed $owned = null,
        public array $inherit = [],
    ) {}

    /** What this entry owns: `owned` when given, else `value`. */
    public function ownedValue(): mixed
    {
        return $this->owned ?? $this->value;
    }
}
