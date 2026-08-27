<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Spatie\Activitylog\Models\Activity;

/**
 * The spatie/laravel-activitylog table, as ONE shared/ stub — run in BOTH the central pass and every
 * tenant pass by beam-tenancy's `registerSharedMigrationsPath()`, so `activity_log` exists identically
 * in the central schema and in each tenant schema.
 *
 * Squashed pre-prod (no deployed data to preserve migration history for): this consolidates the former
 * central-only `create_central_activity_log_table` and the tenant-only `create_activity_log_table` —
 * exactly the consolidation `create_statuses_table.php.stub` already did for spatie model-status, and
 * for the same reason: two independently-maintained copies of one library's table drift by construction.
 *
 * THE CENTRAL SHAPE WINS AS CANONICAL, because it strictly dominates. Its morph id columns are STRINGS,
 * so a bigint personal-access-token id, a uuid user id, and a string tenant slug can all be a subject or
 * causer; the tenant copy's `nullableUuidMorphs` could hold only the uuid case. Nothing depended on the
 * narrower shape, and {@see \Splicewire\Beam\Models\CentralActivityLog} records tenant slugs as subjects.
 *
 * `batch_uuid` IS NOW CONDITIONAL ON THE INSTALLED MAJOR, and its history is worth keeping because it
 * reverses twice.
 * It was ADDED here deliberately (`a5df9e1`) when the estate resolved activitylog **4.x**, whose
 * `ActivityLogger` writes the column whenever a batch is open — the retired central-only migration had
 * omitted it on a then-premature reading of v5, and that omission surfaced as
 * `column "batch_uuid" of relation "central_activity_log" does not exist`, failing 4 tests. Beam now
 * resolves **5.0.0**, which deletes the batch system outright (`LogBatch`, the facade,
 * `Activity::batch()`), and `grep -r batch_uuid vendor/spatie/laravel-activitylog/src` returns nothing.
 *
 * The estate has NOT flipped with it, which is the whole reason this is a condition and not a deletion.
 * Measured 2026-08-27: 43 roots resolve activitylog across **three** versions — 4.12.3, 5.0.0 and 5.1.0 —
 * and the five still on 4.12.3 include `splicewire/splicewire-app`, the flagship. Beam declares
 * `^4.0|^5.0` and publishes this one stub into all of them, so an unconditional column leaves every v5
 * host with a column its library does not know about, and an unconditional deletion reproduces `a5df9e1`
 * on the flagship. Neither is a shape a single literal can express. Narrowing beam to `^5.0` would
 * collapse the condition — that is the real fix, and it is a migration of five roots, not a stub edit.
 *
 * `attribute_changes` is NOT beam's own addition any more, whatever the former comment here claimed:
 * v5 ships it upstream, and it is now where tracked model changes live (`properties` in v5 holds only
 * caller-supplied `withProperties()` data). Beam having carried the column early means the v5 shape and
 * the beam shape happen to agree on it — a coincidence, not a deviation to defend.
 *
 * TABLE NAME comes from the CONFIGURED ACTIVITY MODEL, not a literal and no longer from
 * `activitylog.table_name` — v5 DELETED that config key (and its env). v5's documented lever is instead
 * "subclass `Activity`, set `$table`, point `activitylog.activity_model` at it", so asking that model
 * for its own table is what keeps the model and this migration from disagreeing. It is also
 * version-agnostic: under 4.x `Activity::__construct()` seeds `$table` from `activitylog.table_name`,
 * so `getTable()` returns the configured name there too. Reading the retired key directly would be the
 * silent-wrong-schema class — a host with a v4-era published `activitylog.php` still setting
 * `table_name` would get the migration on the renamed table and the model on `activity_log`.
 *
 * THIS TABLE HAS A HOSTILE COMPETITOR. `lunarphp/core` ships its own `activity_log` migration at a
 * FIXED date (`2026_01_01_900001`) guarded with a bare `Schema::hasTable()`, and its shape is
 * `nullableMorphs` — BIGINT ids — which cannot hold a tenant slug: writing one throws `invalid input
 * syntax for type bigint`. Under two bare existence guards whichever migration sorts first simply owns
 * the table and the loser reports success, which is the silent-wrong-schema failure class.
 *
 * THE GUARD IS THE MECHANISM, not the filename (beam-facade ticket 22). The convergent guard below
 * refuses to report success against Lunar's shape: `hasColumns()` would say the morph columns are
 * present, so a top-up-what-is-missing guard tops up nothing — but the columns are the wrong TYPE, and
 * that is tier three, which throws. See `rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md`.
 *
 * A previous version of this comment required the published copy to be hand-dated `0001_01_01_*` to
 * outrank Lunar. That requirement had no mechanism behind it — it described a hand edit — and was
 * deleted rather than implemented. It now HAS one: `splicewire:beam:install` asks who owns each
 * colliding table (default beam, `--own-tables` to script it) and re-dates the published copy to one
 * tick below Lunar's stamp — see {@see \Splicewire\Beam\Install\TableOwnershipResolver}, driven from
 * {@see \Splicewire\Beam\Console\BeamInstallCommand}. So the ordering is caused by the install and
 * reproduces from a fresh clone, and a host that declines gets the loud migrate above rather than a
 * quiet wrong table. Note the re-date is one tick, never a `0001_01_01_*` band: a band was rejected on
 * merit (beam-facade ticket 22) and would also outrank a host's own deliberate migration.
 *
 * The presence question stays CURRENT-SCHEMA-EXPLICIT rather than `Schema::hasTable()`, because under
 * stancl schema-per-tenant the search_path is `("tenant_x", public)` — a plain hasTable inside a tenant
 * would see the central copy and skip creating the tenant-local one.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named($this->table())
            ->existsUsing(fn (string $table) => $this->existsInCurrentSchema($table))
            ->define(function (Blueprint $table) {
                $table->id();
                $table->string('log_name')->nullable()->index();
                $table->text('description');

                // STRING morphs, not uuid/bigint: central subjects have mixed key types (bigint token id,
                // uuid user id, string tenant slug) and a tenant's subjects are a subset of that range.
                // This is exactly where Lunar's copy conflicts, and where the guard throws.
                $table->string('subject_type')->nullable();
                $table->string('subject_id')->nullable();
                $table->string('event')->nullable();
                $table->string('causer_type')->nullable();
                $table->string('causer_id')->nullable();

                $table->json('attribute_changes')->nullable();
                $table->json('properties')->nullable();

                // `batch_uuid` follows the INSTALLED major, because beam declares `^4.0|^5.0` and this
                // one stub is published into hosts on both. v4's ActivityLogger writes the column on
                // every batched write; v5 deleted the batch system and never touches it. Emitting it
                // unconditionally leaves a v5 host with a column its library does not know about;
                // omitting it unconditionally reproduces `a5df9e1` — `column "batch_uuid" ... does not
                // exist`, 4 failing tests — on the FLAGSHIP, which resolves 4.12.3 while beam resolves
                // 5.0.0. `LogBatch` is the discriminator: present in 4.x, gone in 5.x.
                if (class_exists(\Spatie\Activitylog\LogBatch::class)) {
                    $table->uuid('batch_uuid')->nullable();
                }

                $table->timestamps();

                // Upstream gets these from `nullableMorphs('subject', 'subject')` / `('causer', 'causer')`,
                // which would name them plain `subject` and `causer`. Spelled out here because the morph
                // ids are strings (above), and named explicitly because a Postgres index name is unique
                // per SCHEMA, not per table — `subject` is too generic to hand to one table when this
                // stub runs in the central schema and in every tenant schema.
                $table->index(['subject_type', 'subject_id'], 'activity_log_subject_index');
                $table->index(['causer_type', 'causer_id'], 'activity_log_causer_index');
            })
            ->assert();
    }

    public function down(): void
    {
        if ($this->existsInCurrentSchema($this->table())) {
            Schema::dropIfExists($this->table());
        }
    }

    /**
     * Spatie's own table-name lever, so the model and this migration cannot disagree. v5 removed the
     * `activitylog.table_name` config key; the model IS the lever now, and asking it works on 4.x too.
     */
    private function table(): string
    {
        $model = config('activitylog.activity_model', Activity::class);

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            return 'activity_log';
        }

        return (new $model)->getTable();
    }

    /**
     * Outside Postgres there is no per-connection "current schema"/search_path concern — sqlite and
     * mysql see one namespace per connection, so `Schema::hasTable()` is exact and driver-safe there.
     */
    private function existsInCurrentSchema(string $table): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return Schema::hasTable($table);
        }

        $schema = DB::selectOne('select current_schema() as schema')->schema;

        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
