<?php

declare(strict_types=1);

namespace Splicewire\Beam\Schema;

use Stringable;

/**
 * The single source of truth for the absolute versioned schema `$id` grammar
 * `<base>/<name>/<version>`.
 *
 * An absolute `$id` is a base authority/path, a path-style schema name, and a
 * trailing monotonic integer version — e.g.
 * `https://schemas.splicewire.app/content/article/3`, whose stem is
 * `https://schemas.splicewire.app/content/article`, name `content/article`, and
 * version `3`.
 *
 * The value object is PURE and TOTAL. A well-formed id round-trips byte-for-byte;
 * a malformed or unversioned tail yields a null version rather than throwing, so
 * "does this id carry a version?" is a cheap, total query. Two ids are
 * "comparable" only when they share the same stem (same base + name) — the guard
 * that keeps version comparison from ever crossing schema names.
 *
 * Lives in beam-core (the schema-driven-CMS core) so the migration adapter
 * ({@see SchemaLadderMigrator}) and any beam app share one parser. Replaces the
 * trailing-integer parsing that previously lived duplicated in the database
 * registry (`versionOf`/`stemOf`) and the payload migrator (`version`/`stem`):
 * both now consume this one parser.
 */
final class SchemaId implements Stringable
{
    private function __construct(
        private readonly string $raw,
        private readonly string $stem,
        private readonly ?int $version,
    ) {}

    /**
     * Parse an absolute `$id`. Tolerant: when the trailing segment is not a
     * non-negative integer (an unversioned or malformed tail), the version is
     * null and the entire input is the stem, so the value still round-trips.
     */
    public static function from(string $id): self
    {
        $pos = strrpos($id, '/');

        if ($pos === false) {
            // No path separator at all: there is no stem/version split to make.
            return new self($id, $id, null);
        }

        $stem = substr($id, 0, $pos);
        $tail = substr($id, $pos + 1);

        // The version is the trailing segment only when it is a non-negative
        // integer; an unversioned/malformed tail leaves the version absent. The
        // stem is the portion before the last `/` either way.
        $version = ($tail !== '' && ctype_digit($tail)) ? (int) $tail : null;

        return new self($id, $stem, $version);
    }

    /** The `<base>/<name>` stem — the id sans its trailing version segment. */
    public function stem(): string
    {
        return $this->stem;
    }

    /**
     * The path-style schema name (`<name>`): the stem with its leading base
     * authority/scheme removed. For a non-URL stem this is the stem itself.
     */
    public function name(): string
    {
        $schemeless = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $this->stem) ?? $this->stem;

        $pos = strpos($schemeless, '/');

        return $pos === false ? $schemeless : substr($schemeless, $pos + 1);
    }

    /** The trailing integer version, or null when the id carries no version. */
    public function version(): ?int
    {
        return $this->version;
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
        return $this->version === null ? $this->raw : $this->stem;
    }

    /** A sibling `$id` on the same stem at the given version. */
    public function withVersion(int $version): self
    {
        return new self($this->stem.'/'.$version, $this->stem, $version);
    }

    /** True iff the two ids share the same stem (same base + name). */
    public function isComparableTo(self $other): bool
    {
        return $this->stem === $other->stem;
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
