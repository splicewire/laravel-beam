<?php

namespace Splicewire\Beam\Write\Dedupe;

use InvalidArgumentException;
use Splicewire\Beam\Schema\Keywords;

/**
 * A resolved schema's `x-beam-dedupe` declaration, parsed (beam-facade ticket 50 §4).
 *
 *   x-beam-dedupe: { by: ['email'], mode: 'admit' }
 *
 * {@see self::fromSchema()} is total for the ABSENT case — a schema with no keyword returns null and
 * the stage no-ops, which is every schema in the estate today. It is deliberately LOUD for the
 * malformed case: a declaration with no `by` or an unknown `mode` throws rather than degrading to a
 * no-op, on ticket 50 §11's doctrine that a keyword which quietly does nothing is worse than one
 * that refuses (ticket 40's exact failure mode — effective for one path, inert for another, flagged
 * by nothing).
 */
class DedupeDeclaration
{
    /**
     * @param  list<string>  $by  the payload fields the key is computed from, in the author's
     *                            DECLARED order — never sorted (ticket 50 §8: the author's order is
     *                            stable and visible, and sorting hides a reordering that changes
     *                            nothing)
     */
    public function __construct(
        public readonly array $by,
        public readonly DedupeMode $mode,
    ) {}

    /**
     * The declaration carried by a resolved schema document, or null when it carries none.
     *
     * @param  array<string, mixed>  $schema
     *
     * @throws InvalidArgumentException the keyword is present but malformed
     */
    public static function fromSchema(array $schema): ?self
    {
        $declared = $schema[Keywords::Dedupe] ?? null;

        if ($declared === null) {
            return null;
        }

        if (! is_array($declared)) {
            throw new InvalidArgumentException(
                '['.Keywords::Dedupe.'] must be an object carrying `by` and an optional `mode`.'
            );
        }

        $by = $declared['by'] ?? null;
        if (! is_array($by) || $by === [] || array_filter($by, static fn ($f) => ! is_string($f) || $f === '') !== []) {
            throw new InvalidArgumentException(
                '['.Keywords::Dedupe.'] requires a non-empty `by` list of payload field names.'
            );
        }

        $mode = $declared['mode'] ?? null;
        if ($mode === null) {
            $mode = DedupeMode::default();
        } elseif (! is_string($mode) || ($mode = DedupeMode::tryFrom($mode)) === null) {
            throw new InvalidArgumentException(
                '['.Keywords::Dedupe.'] `mode` must be one of: '
                .implode(', ', array_column(DedupeMode::cases(), 'value')).'.'
            );
        }

        return new self(array_values($by), $mode);
    }
}
