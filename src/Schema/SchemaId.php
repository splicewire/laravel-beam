<?php

namespace Splicewire\Beam\Schema;

use Schemastud\JsonNs\NamespaceUri;

/**
 * The absolute versioned schema `$id` grammar `<base>/<name>/<version>` — beam's ROLE
 * over the one fleet-wide namespace-URI parser (ADR-0191): `SchemaId` IS a
 * {@see NamespaceUri} ("the same grammar, different roles", asset 05 §8), so the parse,
 * stem/version split, tolerant-total round-trip, `withVersion()` sibling derivation,
 * and stem comparison all live upstream in `schemastud/php-json-ns` and are inherited
 * here rather than duplicated.
 *
 * An absolute `$id` is a base authority/path, a path-style schema name, and a trailing
 * monotonic integer version — e.g. `https://schemas.splicewire.app/content/article/3`,
 * whose stem is `https://schemas.splicewire.app/content/article`, name
 * `content/article`, and version `3`.
 *
 * What stays in beam is beam's domain vocabulary only: {@see name()} (the path-style
 * schema name) and {@see recordType()} (the "schema binding → record type" rule) —
 * consumer-domain words the framework-free core must not carry. `isComparableTo()` is
 * retained as the historical spelling of the inherited `sameStemAs()`.
 */
class SchemaId extends NamespaceUri
{
    /**
     * The path-style schema name (`<name>`): the stem with its declared base authority
     * removed. For a non-URL stem this is the stem itself.
     *
     * ## The base is an ARGUMENT because an `$id` cannot supply it
     *
     * A declared authority is a base **authority/path** (`data-schemas.base_uri`), and 8 of the
     * estate's 9 declaring roots are path-shaped — `https://audiostud.io/schemas`,
     * `https://app.splicewire.com/schemas` — against one path-less
     * (`https://schemas.splicewire.com`). Nothing in `https://app.splicewire.com/schemas/content/cell`
     * says whether `schemas` is the authority's path or the name's first segment. The historical
     * implementation guessed "strip up to the first `/` after the host", which is exact for the
     * path-less shape and wrong for the normal one, and the guess had already grown a hand-written
     * compensation at a caller (a hardcoded `'schemas'` segment filter in the flagship's codegen
     * source) — beam-facade ticket 113.
     *
     * Reading `config('data-schemas.base_uri')` here was the alternative and is refused: this is a
     * pure value object over a string, deliberately container-free, and every caller already knows
     * which authority it is reading an `$id` under. Requiring the argument is also what makes the
     * question unskippable at each call site rather than answered wrongly by default.
     *
     * ## A stem that was NOT minted under `$baseUri`
     *
     * Returns the stem's path with only `scheme://host` removed. A foreign authority's path split is
     * genuinely unknowable from here — `https://audiostud.io/schemas/commerce/money` read at a host
     * whose base is `https://fable.pub/schemas` yields `schemas/commerce/money`, which is honest
     * rather than correct. (Whether a vendor DTO should carry a host's authority at all is
     * beam-facade ticket 107; this method does not decide it.)
     *
     * @param  string|bool|null  $baseUri  the declared authority, mirroring `base_uri`'s tri-state —
     *                                     `false`/`null`/non-absolute all take the foreign branch.
     */
    public function name(string|bool|null $baseUri): string
    {
        $stem = $this->stem();

        if (is_string($baseUri)) {
            $base = rtrim(trim($baseUri), '/');

            if ($base !== '' && str_starts_with($stem, $base.'/')) {
                return substr($stem, strlen($base) + 1);
            }
        }

        $schemeless = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $stem) ?? $stem;

        if ($schemeless === $stem) {
            // Not a URL at all — a bare stem is already its own name.
            return $stem;
        }

        $pos = strpos($schemeless, '/');

        return $pos === false ? $schemeless : substr($schemeless, $pos + 1);
    }

    /**
     * The record TYPE this id denotes — the single place the "schema binding → record
     * type" rule lives, shared by every schema-record model that resolves its type off a
     * `schema_ref`. A binding may be a bare stem (`content/article`) — already the record
     * type — or a versioned `$id` (`content/article/2`), stripped to its stem. (Calling
     * `stem()` unconditionally would wrongly drop a bare stem's last name segment.)
     */
    public function recordType(): string
    {
        return $this->version() === null ? (string) $this : $this->stem();
    }

    /** True iff the two ids share the same stem (same base + name). */
    public function isComparableTo(self $other): bool
    {
        return $this->sameStemAs($other);
    }
}
