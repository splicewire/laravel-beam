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
     * The path-style schema name (`<name>`): the stem with its leading base
     * authority/scheme removed. For a non-URL stem this is the stem itself.
     */
    public function name(): string
    {
        $stem = $this->stem();

        $schemeless = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $stem) ?? $stem;

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
