<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * Runtime, DB-backed schema registry (slice 16) — routed through the single table-prefix seam
 * {@see Beam::table()}, `shared/` (central + every tenant) like beam-core's other ubiquitous tables.
 *
 * The filesystem registry holds the app's CODE schemas as committed artifacts. This table is its
 * runtime sibling: a place where a downstream tenant brings its OWN versioned schemas, registered at
 * runtime via the registry-write endpoint without a deploy.
 *
 * Each row is a frozen schema version, keyed by its absolute, versioned `$id` (unique). Like the
 * filesystem store it is write-once: re-registering the same `$id` with the same structural
 * fingerprint is an idempotent no-op; a differing fingerprint is rejected (a changed shape requires
 * a new `$id`/version).
 *
 * Data-preserving rename (NOT drop+create): where a pre-rename `schema_registry` table already
 * exists in THIS schema (an already-deployed host predating this table-prefix seam), it is renamed
 * in place, preserving every frozen row. A fresh install (neither table present) creates the target
 * directly. On PostgreSQL the guard checks the CURRENT schema explicitly (not Schema::hasTable,
 * which follows the tenant search_path `tenant_x, public` and would see public's copy): both
 * migration sets are applied to the one shared schema in a shared-connection test harness, so
 * per-schema presence must be checked against current_schema(). On single-schema drivers
 * (sqlite/mysql — e.g. a beam host or the install-wizard test harness) there is no search_path to
 * be fooled by, so plain Schema::hasTable() is the correct, portable guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Data-preserving rename of the pre-rename table when it is present in THIS schema and the
        // target is not. Still a rename rather than a drop+create: every frozen row is preserved.
        if (! $this->tablePresent($this->target()) && $this->tablePresent('schema_registry')) {
            Schema::rename('schema_registry', $this->target());
        }

        // CONVERGENT guard (rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md): create when absent,
        // top up when a copy is already present, throw when one is present with an incompatible column
        // type. It subsumes the idempotent-re-run guard this file used to carry — and it now also runs
        // over a JUST-RENAMED table, which the old early-return skipped: a pre-rename `schema_registry`
        // from a host predating a column addition is converged onto the current shape rather than left
        // one column short under a new name.
        //
        // The presence question is delegated because on PostgreSQL it is not `Schema::hasTable()`: that
        // follows the tenant search_path (`tenant_x, public`) and would see public's copy, so both
        // migration sets applied to one shared schema in a shared-connection harness must be checked
        // against current_schema(). On single-schema drivers (sqlite/mysql) there is no search_path to
        // be fooled by, and the predicate degrades to plain hasTable().
        ConvergentTable::named($this->target())
            ->existsUsing(fn (string $table) => $this->tablePresent($table))
            ->define(function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('schema_id')->unique();
                $table->string('schema_name')->nullable()->index();
                $table->integer('version')->nullable();
                $table->string('fingerprint');
                $table->jsonb('artifact');
                $table->timestamps();
            })
            ->assert();
    }

    public function down(): void
    {
        // Reverse the rename when the target is the one present; otherwise drop whatever exists.
        if ($this->tablePresent($this->target())) {
            Schema::rename($this->target(), 'schema_registry');
        }
    }

    private function target(): string
    {
        return Beam::table('schemas');
    }

    /**
     * Per-schema presence on PostgreSQL (search_path-proof); plain hasTable elsewhere.
     */
    private function tablePresent(string $table): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return Schema::hasTable($table);
        }

        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$currentSchema, $table],
        ) !== null;
    }
};
