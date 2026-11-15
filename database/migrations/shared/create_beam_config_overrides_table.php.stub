<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;

/**
 * The config override store (ADR-0245 §9; config-sections ticket 05): the operator and tenant tiers' sparse overrides,
 * published to central and to every tenant schema, so a tenant's mixed Release (ticket 08) keeps its config members
 * on the Release's own connection. Two NEW tables; no existing table is touched.
 *
 * - `beam_config_overrides` is the store's OWN append-only revision log (ADR-0245 §Open questions 3, settled by ticket
 *   05): one row per write to one pointer of one section — `set`, an explicit `null`, or `unset` (inherit). A secret's
 *   value is held only here, in `encrypted_value` (Laravel's `encrypted` cast); `value` is never written for a secret.
 *   A row is never updated or deleted, so a version or a Release member that names a revision id keeps selecting
 *   exactly that write.
 * - `beam_config_scopes` is one row per section: the working draft (`draft`, a map of pointer → that pointer's current
 *   revision, never a secret value) and two pins into `rushing/laravel-versioning`'s `beam_versions` — HEAD
 *   (`head_version`, the last saved draft) and `published_version`, which the runtime applies. A draft is pending
 *   when the two differ. Every save and every publish is a version; a version's snapshot carries each pointer's
 *   revision id, `set` and `setAt` for a secret, never its value (ADR-0245 §6).
 *
 * Shipped as a publish-only stub (`runsMigrations` FALSE) to the single `database/migrations/shared/` destination,
 * which beam-tenancy's `registerSharedMigrationsPath()` runs in both the central pass and Stancl's tenant pass. Each
 * table is CONVERGENT (create when absent, top up a matching copy, throw on an incompatible column), so a host that
 * runs both passes into one schema writes nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named(Beam::table('config_overrides'))
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();              // the revision id a version or a Release member names
                $table->string('section');                  // the config root, e.g. `beam.ux`
                $table->string('pointer', 1024);            // RFC 6901, relative to the root
                $table->string('op', 8);                    // set | null | unset
                $table->json('value')->nullable();          // a non-secret `set` only
                $table->text('encrypted_value')->nullable(); // a secret `set` only, encrypted at rest
                $table->unsignedInteger('revision');        // per-section monotonic: 1, 2, 3…
                $table->string('created_by')->nullable();   // the actor's key, as a string (int or uuid hosts)
                $table->timestamp('created_at')->nullable();

                $table->unique(['section', 'revision']);    // monotonic correctness backstop
                $table->index(['section']);
            })
            ->assert();

        ConvergentTable::named(Beam::table('config_scopes'))
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('section')->unique();
                $table->json('draft')->nullable();           // pointer → {op, revision, value?, secret, set, setAt}
                $table->uuid('head_version')->nullable();    // laravel-versioning HEAD: the last saved draft
                $table->uuid('published_version')->nullable(); // what the runtime applies
                $table->timestamps();
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('config_scopes'));
        Schema::dropIfExists(Beam::table('config_overrides'));
    }
};
