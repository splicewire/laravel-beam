<?php

namespace Splicewire\Beam\Config\Generator;

use RuntimeException;

/**
 * A config key the generator cannot turn into a Data property and cannot map to one either (config-sections ticket 13,
 * Part 1).
 *
 * A record property is a PHP identifier. A config key that is not one — `recipient-kinds`, `kind.event`, `0` — can
 * still become a property when it is MAPPABLE: a valid identifier plus a `#[MapName]` carrying the wire key (see
 * {@see ConfigSectionInferrer}). This error is the floor BELOW that: a key that is neither an identifier nor mappable
 * to a distinct one, so no property name and no `#[MapName]` argument can represent it.
 *
 * nette/php-generator throws `Value '0' is not valid name` from deep inside `addPromotedParameter()` at emit time,
 * naming neither the config root, the pointer, nor the offending key. This replaces that with the root, the JSON
 * pointer to the record that holds the key, the key itself, and why it is unmappable — the three things an operator
 * needs to find and fix the config.
 */
class ConfigSectionInferenceError extends RuntimeException
{
    public function __construct(
        public readonly string $root,
        public readonly string $pointer,
        public readonly string $key,
        public readonly string $why,
    ) {
        parent::__construct(sprintf(
            'Cannot infer a Config Section for root `%s`: the key `%s` at `%s` %s. '
            .'A config key becomes a Data property, which must be a PHP identifier or a key mappable to one '
            .'(a `#[MapName]` carries the wire key); this key is neither.',
            $root,
            $key,
            $pointer === '' ? '(root)' : $pointer,
            $why,
        ));
    }
}
