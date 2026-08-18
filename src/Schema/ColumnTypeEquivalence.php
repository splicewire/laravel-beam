<?php

namespace Splicewire\Beam\Schema;

use Splicewire\Beam\Doctor\MigrationOrderingAudit;

/**
 * Answers the one question tier three of {@see ConvergentTable} turns on: does the column already in the
 * database hold the type this migration declares?
 *
 * The comparison is between two different vocabularies — a Blueprint's LARAVEL type (`string`, `uuid`,
 * `bigInteger`) and the DATABASE's own type name as `Schema::getColumns()` reports it (`varchar`, `uuid`,
 * `int8`) — so it needs a map, and the map is per driver because the same Laravel type compiles
 * differently: `uuid()` is a real `uuid` on Postgres, `char(36)` on MySQL, and plain `varchar` on sqlite.
 *
 * **Three answers, not two.** An unmapped Laravel type returns `null` — UNVERIFIED, never a conflict.
 * This is the same conservative posture {@see MigrationOrderingAudit} takes with a
 * dynamically-named table: a false conflict stops an install, a missed one leaves the status quo. The map
 * is therefore allowed to be incomplete, and is meant to grow when a real column type goes unverified.
 *
 * **What the map cannot do, by construction.** sqlite compiles `uuid`, `string`, and `ulid` all to
 * `varchar`, and `integer`/`bigInteger` both to `integer`, so those pairs are indistinguishable there —
 * accepted rather than papered over, because sqlite is the test driver and Postgres is the production one.
 * The damage cases this exists to catch survive it: `string` vs `bigint` and `uuid` vs `bigint` are
 * different families on every driver, and those are the two sightings with proven damage.
 */
class ColumnTypeEquivalence
{
    /**
     * Laravel Blueprint type => driver => acceptable `type_name` values from `Schema::getColumns()`.
     *
     * `*` is the fallback; a named driver REPLACES it rather than extending it, so a narrow driver
     * (MySQL's `char(36)` uuid) does not inherit the broad one.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected const ACCEPTS = [
        // Text
        'string' => ['*' => ['varchar', 'bpchar', 'char', 'nvarchar', 'character varying']],
        'char' => ['*' => ['char', 'bpchar', 'varchar', 'character']],
        'text' => ['*' => ['text', 'clob'], 'mysql' => ['text', 'tinytext', 'mediumtext', 'longtext'], 'mariadb' => ['text', 'tinytext', 'mediumtext', 'longtext']],
        'tinyText' => ['*' => ['text', 'clob'], 'mysql' => ['tinytext', 'text'], 'mariadb' => ['tinytext', 'text']],
        'mediumText' => ['*' => ['text', 'clob'], 'mysql' => ['mediumtext', 'longtext', 'text'], 'mariadb' => ['mediumtext', 'longtext', 'text']],
        'longText' => ['*' => ['text', 'clob'], 'mysql' => ['longtext', 'mediumtext', 'text'], 'mariadb' => ['longtext', 'mediumtext', 'text']],

        // Identifiers
        'uuid' => ['*' => ['uuid'], 'mysql' => ['char', 'varchar'], 'mariadb' => ['char', 'varchar', 'uuid'], 'sqlite' => ['varchar', 'char']],
        'ulid' => ['*' => ['char', 'varchar', 'bpchar']],

        // Numbers
        'integer' => ['*' => ['integer', 'int', 'int4'], 'mysql' => ['int'], 'mariadb' => ['int']],
        'tinyInteger' => ['*' => ['tinyint', 'int2', 'smallint', 'integer']],
        'smallInteger' => ['*' => ['smallint', 'int2', 'integer']],
        'mediumInteger' => ['*' => ['mediumint', 'integer', 'int', 'int4']],
        'bigInteger' => ['*' => ['bigint', 'int8'], 'sqlite' => ['integer', 'bigint']],
        'decimal' => ['*' => ['decimal', 'numeric']],
        'float' => ['*' => ['float', 'float4', 'real', 'double']],
        'double' => ['*' => ['double', 'double precision', 'float8', 'float', 'real']],

        // Booleans — sqlite compiles `boolean()` to `tinyint(1)`, MySQL to `tinyint`, Postgres to `bool`.
        'boolean' => ['*' => ['boolean', 'bool', 'tinyint', 'integer']],

        // Structured
        'json' => ['*' => ['json', 'jsonb'], 'sqlite' => ['text'], 'mysql' => ['json', 'longtext'], 'mariadb' => ['json', 'longtext']],
        'jsonb' => ['*' => ['jsonb', 'json'], 'sqlite' => ['text'], 'mysql' => ['json', 'longtext'], 'mariadb' => ['json', 'longtext']],

        // Time
        'date' => ['*' => ['date']],
        'time' => ['*' => ['time', 'time without time zone']],
        'timeTz' => ['*' => ['timetz', 'time', 'time with time zone']],
        'dateTime' => ['*' => ['timestamp', 'datetime', 'timestamp without time zone']],
        'dateTimeTz' => ['*' => ['timestamptz', 'timestamp', 'datetime', 'timestamp with time zone']],
        'timestamp' => ['*' => ['timestamp', 'datetime', 'timestamp without time zone']],
        'timestampTz' => ['*' => ['timestamptz', 'timestamp', 'datetime', 'timestamp with time zone']],
        'year' => ['*' => ['year', 'integer', 'int', 'int4']],

        // Bytes
        'binary' => ['*' => ['blob', 'bytea', 'varbinary', 'longblob', 'binary']],
    ];

    /**
     * TRUE / FALSE / null-for-unverified. `$actual` is the raw `type_name` from `Schema::getColumns()`.
     */
    public static function matches(string $driver, string $declared, string $actual): ?bool
    {
        $accepted = static::ACCEPTS[$declared][$driver] ?? static::ACCEPTS[$declared]['*'] ?? null;

        if ($accepted === null) {
            return null;
        }

        return in_array(strtolower(trim($actual)), $accepted, true);
    }
}
