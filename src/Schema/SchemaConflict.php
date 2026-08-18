<?php

namespace Splicewire\Beam\Schema;

/**
 * One reason a table cannot be converged onto the shape a migration declares — the third tier of
 * {@see ConvergentTable}'s verdict table, where "add the missing thing" is not available.
 *
 * Three kinds, and the distinction is not cosmetic: each names a DIFFERENT repair, and the whole
 * point of throwing rather than skipping is that a human is told which one.
 *
 * - `type` — the column exists with an incompatible database type. The damage class this utility
 *   exists to end: `hasColumns()` returns TRUE for it, so a bare existence guard converges nothing
 *   and reports success against a table the app cannot use (lunarphp's bigint `nullableMorphs`
 *   where beam's `activity_log` needs string morphs; the bigint-vs-uuid `roles.id` break).
 * - `required-addition` — a declared column is NOT NULL with no default and the table already holds
 *   rows, so the ALTER itself would fail. Convergence handles ABSENCE, not a populated table.
 * - `required-residue` — a column the declaration does not know about is NOT NULL with no default,
 *   so every canonical insert fails AFTER a textbook-clean convergence. Found on the submissions
 *   fork (beam-facade ticket 23), which is neither a missing-column nor a type case: the fork's own
 *   `schema_record_id` survives convergence and then rejects every write.
 */
class SchemaConflict
{
    public const TYPE = 'type';

    public const REQUIRED_ADDITION = 'required-addition';

    public const REQUIRED_RESIDUE = 'required-residue';

    public function __construct(
        public string $kind,
        public string $table,
        public string $column,
        public string $detail,
    ) {}

    public static function type(string $table, string $column, string $declared, string $actual): self
    {
        return new self(
            self::TYPE,
            $table,
            $column,
            "`{$table}.{$column}` is `{$actual}` in the database but this migration declares `{$declared}`. "
            .'Convergence adds missing columns; it does not convert a column that already exists with the '
            .'wrong type. Reconcile the two declarations, or migrate the column deliberately.',
        );
    }

    public static function requiredAddition(string $table, string $column): self
    {
        return new self(
            self::REQUIRED_ADDITION,
            $table,
            $column,
            "`{$table}.{$column}` is declared NOT NULL with no default and `{$table}` already holds rows, "
            .'so it cannot be added. Give the column a default, make it nullable, or backfill it in a '
            .'migration of its own.',
        );
    }

    public static function requiredResidue(string $table, string $column): self
    {
        return new self(
            self::REQUIRED_RESIDUE,
            $table,
            $column,
            "`{$table}.{$column}` exists, is NOT NULL with no default, and this migration does not declare it — "
            .'so the table converges cleanly and then rejects every insert this package makes. Usually a fork '
            .'of the table under the same name. Reconcile it, or pass its name to `ignoringColumns()` if the '
            .'host is filling it deliberately.',
        );
    }
}
