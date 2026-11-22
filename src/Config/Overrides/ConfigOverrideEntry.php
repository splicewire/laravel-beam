<?php

namespace Splicewire\Beam\Config\Overrides;

use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Enums\ConfigOverrideOp;

/**
 * One pointer of a section's published override layer, as the overlay applies it and the reader reports it. `value`
 * is the effective value the tier sets (null for an explicit null; the DECRYPTED secret, which is only ever written
 * into the running configuration and never onto a wire or into a version).
 *
 * `inherit` lists the keys below `pointer` (relative pointers) that an object `set` does NOT own: the declared secrets
 * split out of it, and the keys whose ceiling does not admit the operator tier, at the HIGHEST such pointer (a
 * host-owned map is inherited whole, so a new host entry still arrives), which the ceiling guard already forces to equal
 * their lower-tier value. They are taken from the document below this entry each time it is applied
 * ({@see valueOver()}), so the object keeps REPLACE semantics for everything it does own (an operator-owned map still
 * drops an entry it leaves out), unsetting a split secret inherits the lower tier (except inside a LIST item, which is
 * never inherited: config-sections 20), and a host-owned key follows the host
 * after a deploy (build-qa's probes 2 and 3, 2026-11-16).
 */
final class ConfigOverrideEntry
{
    /**
     * @param  list<string>  $inherit
     */
    public function __construct(
        public readonly string $pointer,
        public readonly ConfigOverrideOp $op,
        public readonly mixed $value,
        public readonly bool $secret,
        public readonly string $revision,
        public readonly ?int $version,
        public readonly ?string $setAt,
        public readonly array $inherit = [],
    ) {}

    /**
     * The value this entry writes over `$doc` (the section document below it): `value`, with each inherited leaf taken
     * from `$doc`, and left out where `$doc` has none.
     *
     * @param  array<array-key, mixed>  $doc
     */
    public function valueOver(array $doc): mixed
    {
        return self::fill($this->value, $this->pointer, $this->inherit, $doc);
    }

    /**
     * @param  list<string>  $inherit
     * @param  array<array-key, mixed>  $doc
     */
    public static function fill(mixed $value, string $pointer, array $inherit, array $doc): mixed
    {
        if ($inherit === [] || ! is_array($value)) {
            return $value;
        }

        foreach ($inherit as $leaf) {
            [$exists, $lower] = JsonPointer::get($doc, $pointer.$leaf);

            if ($exists) {
                self::put($value, JsonPointer::segments($leaf), $lower);
            }
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $doc
     * @param  list<string>  $segments
     */
    private static function put(array &$doc, array $segments, mixed $value): void
    {
        $key = array_shift($segments);

        if ($segments === []) {
            $doc[$key] = $value;

            return;
        }

        if (! isset($doc[$key]) || ! is_array($doc[$key])) {
            $doc[$key] = [];
        }

        self::put($doc[$key], $segments, $value);
    }
}
