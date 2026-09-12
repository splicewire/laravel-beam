<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;

/**
 * ADDITIVE ALTER — widens `beam_hooks.owner_id` and `beam_hooks.subject_id` from bigint to string on
 * hosts that were migrated before `create_beam_hooks_table.php.stub` declared them that way.
 *
 * ## Why an ALTER exists at all, when the create stub is already correct
 *
 * The create stub's own docblock carries the full argument for the type; this file exists because
 * that edit **reaches only a fresh database**. A host that already ran the create keeps the shape
 * the create had on the day it ran, and no re-publish and no re-read of the stub can change that.
 *
 * The convergent guard cannot reach it either, and that is the load-bearing half.
 * {@see ConvergentTable} is explicit about its three tiers: table absent ⇒ create, column absent ⇒
 * add, **column present with the wrong type ⇒ throw**. Convergence handles ABSENCE, never CONFLICT.
 * So `ConvergentTable::named('beam_hooks')` re-run against a bigint `owner_id` does not repair it —
 * it is precisely the tier-three conflict the guard exists to *report*. A real `ALTER` is the only
 * instrument that reaches an already-migrated host, which is why this is a separately stamped file
 * and not an edit to the create. (`relax_guest_token_landing_url_nullability.php.stub` in
 * `splicewire/laravel-beam-accounts` is the estate's worked precedent for this split.)
 *
 * Listed AFTER `shared/create_beam_hooks_table` in `BeamServiceProvider::configurePackage()`:
 * spatie/laravel-package-tools stamps entries a second apart in declared order at publish time, so
 * the create always precedes its own ALTER on disk. On a host that never had the table, the
 * `hasTable` guard makes this a no-op and the create — which now emits strings — is the whole story.
 *
 * ## Why it reads the column before writing
 *
 * Not for idempotence. Postgres' `ALTER COLUMN … TYPE` is already repeatable, and this stub must be
 * republish-safe anyway. The read is there because `->change()` is a **full column redefinition**,
 * not a type delta: it restates type, nullability and default from this file's declaration and
 * silently resets anything a host legitimately changed about the column. Guarding on the column's
 * actual type means the ALTER touches only the hosts that genuinely diverge and is a **true** no-op
 * — not merely a harmless one — everywhere else. On sqlite and MySQL, where `->change()` is a table
 * REBUILD rather than a catalog flip, "no-op" is worth considerably more than "idempotent".
 *
 * ## `using`, and the index
 *
 * Postgres will not cast bigint to a character type implicitly: without a `USING` clause the ALTER
 * dies with *"column cannot be cast automatically to type character varying"*. `->using('… ::text')`
 * is Laravel's seam for it ({@see \Illuminate\Database\Schema\Grammars\PostgresGrammar::compileChange()});
 * every other grammar ignores the modifier, so one declaration serves both drivers.
 *
 * The composite morph index is **kept, not dropped and recreated**. Postgres rebuilds a dependent
 * index as part of the type change, and Laravel's sqlite path (`BlueprintState` + `compileAlter`)
 * recreates the table's indexes with the table. Dropping it here would leave hosts whose ALTER
 * succeeded and whose recreate did not with a silently missing index, and the names are the ones the
 * create stub still declares — see its note on declaring the index unnamed.
 */
return new class extends Migration
{
    /** The morph pairs on this table, in declaration order. Both are nullable, both are morph ids. */
    private const COLUMNS = ['subject_id', 'owner_id'];

    /**
     * `type_name` values (as `Schema::getColumns()` reports them) that already hold a string. The
     * vocabulary is the database's, not Laravel's — the same distinction
     * {@see \Rushing\SchemaConvergence\ColumnTypeEquivalence} is built around — and the list is the
     * character family plus `uuid`, because a host that went all the way to a native uuid column has
     * a column this ALTER must not touch either.
     */
    private const ALREADY_STRING = [
        'varchar', 'character varying', 'bpchar', 'char', 'character', 'nvarchar', 'text', 'clob', 'uuid',
    ];

    public function up(): void
    {
        $table = Beam::table('hooks');

        // A host whose beam install predates the table has nothing to alter and must not fatal. The
        // create stub owns that case.
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if ($this->alreadyString($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->string($column)
                    ->nullable()
                    // Postgres only; see the docblock. `::text` widens without a length ceiling,
                    // and the declared `varchar(255)` is what the column lands as.
                    ->using($column.'::text')
                    ->change();
            });
        }
    }

    /**
     * Deliberately IRREVERSIBLE rather than a narrowing back to bigint.
     *
     * Narrowing is not the inverse of widening: rows written while the column was a string hold uuid
     * and slug owners that no bigint can represent, so a `down()` that works on an empty table and
     * destroys data on a used one is worse than one that says nothing. The `up()` repairs a defect;
     * it is not a reversible design choice.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }

    private function alreadyString(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $found) {
            if (($found['name'] ?? null) !== $column) {
                continue;
            }

            return in_array(strtolower((string) ($found['type_name'] ?? '')), self::ALREADY_STRING, true);
        }

        // Column absent entirely — the convergent create owns that case, not this ALTER.
        return true;
    }
};
