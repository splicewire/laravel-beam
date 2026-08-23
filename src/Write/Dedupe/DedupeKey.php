<?php

namespace Splicewire\Beam\Write\Dedupe;

use Splicewire\Beam\Concerns\Deduplicates;

/**
 * The match-key recipe (beam-facade ticket 50 §8, §9): sha256 over the capture SCOPE plus the
 * declared field values, in the keyword's declared order, string values trimmed and casefolded.
 *
 * ## The recipe is WRITE-ONCE
 *
 * Once rows carry keys, changing the normalization, the ordering, the separator or the scope
 * silently partitions old rows from new — ticket 61's `$id` lesson in another medium, and nothing
 * in the estate can flag it, because both halves are valid digests. `DedupeKeyRecipeTest` pins a
 * LITERAL expected digest for a fixed input precisely so an edit here fails loudly rather than
 * drifting. If the recipe genuinely must change, that is a backfill, not an edit.
 *
 * ## Why a hash, and why the scope is inside it
 *
 * A hash, because the payload still carries the readable address — nothing is lost but
 * reversibility, and a raw email in a new indexed column would put PII in a second place. The scope
 * INSIDE it, because that makes `dedupe_key` globally comparable on its own: a host's distinct
 * count is a bare `distinct('dedupe_key')` with no where-clause over one plain index.
 *
 * The scope is emphatically NOT `schema_ref` — that is a VERSIONED absolute `$id` (tickets 48, 61),
 * so re-stemming a schema (which the beam-facade map did twice) would silently reset every host's
 * dedupe universe. It comes from {@see Deduplicates::dedupeScope()},
 * which names the column by ROLE.
 *
 * ## A missing declared field means NO key at all
 *
 * Not a key over the absence. Hashing the absence makes every payload missing the field collide
 * with every other one — and under `reject` that refuses all of them. The same argument covers a
 * present-but-empty string and a non-scalar value: both are "no usable value", so both yield no
 * key and dedupe simply does not apply to that capture.
 */
class DedupeKey
{
    /** ASCII unit separator — part of the write-once recipe. Not a stylistic choice. */
    public const Separator = "\x1f";

    /**
     * The key for this capture, or null when a declared field has no usable value.
     *
     * @param  string  $scope  the capture universe this key is comparable within
     * @param  array<string, mixed>  $payload
     */
    public static function compute(string $scope, DedupeDeclaration $declaration, array $payload): ?string
    {
        $parts = [$scope];

        foreach ($declaration->by as $field) {
            $normalized = self::normalize($payload[$field] ?? null);

            if ($normalized === null) {
                return null;
            }

            $parts[] = $normalized;
        }

        return hash('sha256', implode(self::Separator, $parts));
    }

    /**
     * Generic normalization — trim + casefold for strings, canonical scalar text otherwise. There is
     * deliberately NO per-field normalization surface in the keyword until a host needs one
     * (ticket 50 §8): `Writer@x.com` and `writer@x.com` being two rows is the defect this fixes, and
     * anything finer is a widening, which is a decision (ticket 04).
     *
     * A scalar arriving typed differently (`1` vs `"1"`) normalizes to the same text on purpose —
     * a form field is the same value however the transport typed it.
     */
    private static function normalize(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : mb_strtolower($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
